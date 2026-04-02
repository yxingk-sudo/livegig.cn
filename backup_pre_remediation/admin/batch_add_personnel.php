<?php
session_start();
require_once '../config/database.php';
// 更可靠地包含page_functions.php，如果文件不存在则定义默认函数
$page_functions_path = __DIR__ . '/page_functions.php';
if (file_exists($page_functions_path)) {
    require_once $page_functions_path;
} else {
    // 定义默认的getCurrentPage函数
    if (!function_exists('getCurrentPage')) {
        function getCurrentPage() {
            return basename($_SERVER['PHP_SELF']);
        }
    }
}

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 获取当前步骤
$step = isset($_GET['step']) ? intval($_GET['step']) : 1;
if ($step < 1 || $step > 2) $step = 1;

$message = '';
$error = '';

// 初始化变量
$original_input_text = '';
$parsed_personnel = [];

// 检查是否从第二步返回编辑
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_SESSION['batch_add_original_input'])) {
    // 从 SESSION 中恢复原始输入文本
    $original_input_text = $_SESSION['batch_add_original_input'];
    // 清除 SESSION 数据，避免下次误用
    unset($_SESSION['batch_add_original_input']);
    unset($_SESSION['batch_add_personnel_data']);
}

// 处理第一步表单提交
if ($step === 1 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 检查是否是从第二步返回到第一步
    if (isset($_POST['action']) && $_POST['action'] === 'return_to_edit') {
        // 从隐藏字段获取原始输入文本
        $original_input_text = $_POST['original_input_text'] ?? '';
        
        // 如果没有原始输入文本，尝试从personnel_data重建
        if (empty($original_input_text)) {
            $personnel_data = $_POST['personnel_data'] ?? '';
            if (!empty($personnel_data)) {
                $parsed_data = json_decode($personnel_data, true);
                if (!empty($parsed_data)) {
                    foreach ($parsed_data as $person) {
                        $original_input_text .= $person['name'] . "\t" . 
                                              ($person['id_card'] ?? '') . "\t" . 
                                              ($person['phone'] ?? '') . "\t" . 
                                              ($person['gender'] ?? '其他') . "\t" . 
                                              ($person['email'] ?? '') . "\n";
                    }
                }
            }
        }
        // 不需要解析，只是回显原始文本供用户编辑
    } else {
        $input_text = trim($_POST['personnel_data'] ?? '');
        
        if (empty($input_text)) {
            $error = '请输入人员信息';
        } else {
            // 保存原始输入文本
            $original_input_text = $input_text;
            
            // 解析人员信息
            $lines = explode("\n", $input_text);
            $parsed_personnel = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // 尝试不同的分隔符解析
                $person = [];
                
                // 尝试制表符分隔
                if (strpos($line, "\t") !== false) {
                    $fields = explode("\t", $line);
                } 
                // 尝试逗号分隔
                elseif (strpos($line, ",") !== false) {
                    $fields = explode(",", $line);
                }
                // 尝试空格分隔
                else {
                    $fields = preg_split('/\s+/', $line);
                }
                
                // 根据字段数量解析信息
                $person['name'] = trim($fields[0] ?? '');
                $person['id_card'] = isset($fields[1]) ? trim($fields[1]) : '';
                $person['phone'] = isset($fields[2]) ? trim($fields[2]) : '';
                $person['gender'] = isset($fields[3]) ? trim($fields[3]) : '其他';
                $person['email'] = isset($fields[4]) ? trim($fields[4]) : '';
                
                // 如果姓名为空，跳过
                if (empty($person['name'])) continue;
                
                // 根据身份证号判断性别（如果未指定）
                if ($person['gender'] === '其他' && !empty($person['id_card']) && strlen($person['id_card']) >= 17) {
                    $gender_digit = intval(substr($person['id_card'], 16, 1));
                    $person['gender'] = ($gender_digit % 2 === 1) ? '男' : '女';
                }
                
                $parsed_personnel[] = $person;
            }
        }
    }
}

include 'includes/header.php';
?>

<style>
    .batch-input-area {
        min-height: 350px;
        height: 350px;
        font-family: 'Courier New', Courier, monospace;
        font-size: 14px;
        line-height: 1.6;
        padding: 15px;
        border: 2px solid #ced4da;
        border-radius: 5px;
        transition: border-color 0.3s ease;
        resize: vertical;
    }
    
    @media (min-width: 1200px) {
        .batch-input-area {
            min-height: 350px;
            height: 350px;
        }
    }
    
    @media (min-width: 1400px) {
        .batch-input-area {
            min-height: 350px;
            height: 350px;
        }
    }
    
    .batch-input-area:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        outline: none;
    }
    .preview-card {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        background-color: #f8f9fa;
    }
    .person-count {
        font-size: 18px;
        font-weight: bold;
        color: #0d6efd;
    }
    .format-example {
        background-color: #e9ecef;
        padding: 10px;
        border-radius: 5px;
        font-family: monospace;
        font-size: 13px;
    }
</style>

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle"></i> <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-<?php echo $step === 1 ? '1' : '2'; ?>-circle-fill"></i>
                        步骤 <?php echo $step; ?>：<?php echo $step === 1 ? '粘贴团队人员信息' : '分配部门和职位'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($step === 1): ?>
                        <!-- 第一步：信息输入 -->
                        <?php if (!empty($parsed_personnel)): ?>
                            <!-- 显示已识别的人员信息，准备进入第二步 -->
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle"></i> 
                                成功识别 <span class="person-count"><?php echo count($parsed_personnel); ?></span> 名人员
                            </div>
                            
                            <div class="preview-card">
                                <h6>识别的人员列表：</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped">
                                        <thead>
                                            <tr>
                                                <th width="30">#</th>
                                                <th>姓名 <span class="text-danger">*</span></th>
                                                <th>证件号码</th>
                                                <th>手机号码</th>
                                                <th>性别</th>
                                                <th>邮箱</th>
                                                <th>状态</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($parsed_personnel as $index => $person): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($person['name']); ?></strong>
                                                </td>
                                                <td>
                                                    <?php if (!empty($person['id_card'])): ?>
                                                        <span class="badge bg-info"><?php echo htmlspecialchars($person['id_card']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">未提供</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($person['phone'])): ?>
                                                        <span class="badge bg-success"><?php echo htmlspecialchars($person['phone']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">未提供</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $person['gender'] == '其他' ? 'bg-secondary' : 'bg-primary'; ?>">
                                                        <?php echo htmlspecialchars($person['gender']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($person['email'])): ?>
                                                        <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($person['email']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">未提供</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $missing_fields = [];
                                                    if (empty($person['id_card'])) $missing_fields[] = '证件号';
                                                    if (empty($person['phone'])) $missing_fields[] = '手机号';
                                                    
                                                    if (empty($missing_fields)): ?>
                                                        <span class="badge bg-success">✓ 信息完整</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">⚠ 缺少: <?php echo implode(', ', $missing_fields); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>智能识别结果：</strong>成功识别 <?php echo count($parsed_personnel); ?> 人的信息。
                                点击“下一步”为这些人员分配部门和职位，缺少的信息可以后续在人员管理中补充。
                            </div>
                            
                            <form method="post" action="batch_add_personnel_step2.php" id="nextStepForm">
                                <input type="hidden" name="personnel_data" value='<?php echo htmlspecialchars(json_encode($parsed_personnel)); ?>'>
                                <input type="hidden" name="original_input_text" value='<?php echo isset($original_input_text) ? htmlspecialchars($original_input_text) : ''; ?>'>
                                <div class="text-end">
                                    <!-- 使用表单提交方式返回修改，保留原始数据 -->
                                    <button type="submit" name="action" value="return_to_edit" class="btn btn-secondary" onclick="document.getElementById('nextStepForm').action='?step=1';">
                                        <i class="bi bi-arrow-left-circle"></i> 返回修改
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-arrow-right-circle"></i> 下一步
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <!-- 第一步：输入人员信息 -->
                            <form method="post" action="?step=1">
                                <div class="mb-3">
                                    <label for="personnel_data" class="form-label">
                                        <i class="bi bi-people"></i> 请粘贴团队人员信息
                                    </label>
                                    <div class="card border-primary">
                                        <div class="card-body p-0">
                                            <textarea class="form-control batch-input-area" id="personnel_data" name="personnel_data" 
                                                      placeholder="支持以下格式：
1. 制表符分隔：姓名	证件号	手机号	性别	邮箱
2. 逗号分隔：姓名,证件号,手机号,性别,邮箱
3. 空格分隔：姓名 证件号 手机号 性别 邮箱

示例：
张三	110101199001011234	13800138000	男	zhangsan@example.com
李四	110101199001011235	13800138001	女	lisi@example.com
王五	110101199001011236	13800138002	男	wangwu@example.com
赵六	110101199001011237	13800138003	女	zhaoliu@example.com
钱七	110101199001011238	13800138004	男	qianqi@example.com
孙八	110101199001011239	13800138005	女	sunba@example.com"><?php echo isset($original_input_text) ? htmlspecialchars($original_input_text) : ''; ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="alert alert-info">
                                        <h6 class="alert-heading">
                                            <i class="bi bi-info-circle"></i> 格式说明
                                        </h6>
                                        <p class="mb-1">系统支持多种分隔符，自动识别以下信息：</p>
                                        <ul class="mb-1">
                                            <li><strong>姓名</strong>（必填）</li>
                                            <li><strong>证件号码</strong>（选填，用于性别自动识别）</li>
                                            <li><strong>手机号码</strong>（选填）</li>
                                            <li><strong>性别</strong>（选填，可自动根据证件号识别）</li>
                                            <li><strong>邮箱</strong>（选填）</li>
                                        </ul>
                                        <p class="mb-0">如果未提供性别且有证件号，系统会根据身份证号自动判断性别。</p>
                                    </div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> 解析人员信息
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="text-center mt-3">
                <a href="personnel_enhanced.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> 返回人员管理
                </a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>