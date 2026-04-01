<?php
// 启动会话
session_start();

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 设置页面特定变量
$page_title = '批量添加人员 - 第一步';
$active_page = 'batch_add_personnel';
$show_page_title = '批量添加人员';
$page_icon = 'people';

// 包含统一头部文件
include 'includes/header.php';

// 数据库连接
$database = new Database();
$pdo = $database->getConnection();

try {
    if (!$pdo) {
        throw new Exception("无法连接到数据库");
    }
} catch(Exception $e) {
    echo '<div class="alert alert-danger">数据库连接失败: ' . $e->getMessage() . '</div>';
    include 'includes/footer.php';
    exit;
}

// 获取当前用户的项目信息
$project_id = $_SESSION['project_id'] ?? 0;
$project_name = '';

if ($project_id > 0) {
    try {
        // 获取项目名称
        $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        $project_name = $project['name'] ?? '';
    } catch(PDOException $e) {
        $error_message = "获取项目信息失败: " . $e->getMessage();
    }
}

// 处理第一步：解析人员信息
$parsed_personnel = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'parse_personnel') {
    $personnel_data = $_POST['personnel_data'] ?? '';
    
    if (empty($personnel_data)) {
        $error = '请输入人员信息！';
    } else {
        $lines = explode("\n", $personnel_data);
        
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $info = parsePersonnelInfo($line);
            if ($info) {
                $info['original_line'] = $line;
                $info['index'] = $index;
                $parsed_personnel[] = $info;
            }
        }
        
        if (empty($parsed_personnel)) {
            $error = '未能识别任何有效的人员信息！';
        }
    }
}

// 解析人员信息函数（智能版 - 支持英文姓名和不完整信息）
function parsePersonnelInfo($line) {
    $line = trim($line);
    if (empty($line)) return false;

    // 替换中文符号为英文符号
    $line = str_replace(['，', '；', '：', '\t'], [',', ';', ':', ','], $line);
    
    $result = [
        'name' => '',
        'id_card' => '',
        'phone' => '',
        'gender' => '其他',
        'email' => ''
    ];
    
    // 智能解析：优先按逗号和分号分割，保护英文姓名中的空格
    $primary_parts = preg_split('/[,;]+/', $line);
    $all_parts = [];
    
    foreach ($primary_parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        
        // 如果这一段包含明显的非姓名信息（数字、邮箱等），按空格分割
        if (preg_match('/\d{6,}|@|\.[a-z]{2,}/i', $part)) {
            $sub_parts = preg_split('/\s+/', $part);
            $all_parts = array_merge($all_parts, array_filter(array_map('trim', $sub_parts)));
        } else {
            // 可能是姓名，保持完整
            $all_parts[] = $part;
        }
    }
    
    // 如果没有逗号分号分割，尝试智能空格分割
    if (count($all_parts) <= 1) {
        $space_parts = preg_split('/\s+/', $line);
        $space_parts = array_filter(array_map('trim', $space_parts));
        
        if (count($space_parts) > 1) {
            // 尝试智能合并姓名部分
            $all_parts = smartNameParsing($space_parts);
        } else {
            $all_parts = $space_parts;
        }
    }
    
    if (empty($all_parts)) return false;
    
    // 第一个部分作为姓名
    $result['name'] = array_shift($all_parts);
    
    // 解析剩余部分
    foreach ($all_parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        
        // 判断是否为手机号码（6-15位数字）
        if (preg_match('/^\d{6,15}$/', $part)) {
            if (empty($result['phone'])) {
                $result['phone'] = $part;
            }
            continue;
        }
        
        // 判断是否为证件号码（各种格式）
        if (isValidIdCard($part)) {
            if (empty($result['id_card'])) {
                $result['id_card'] = $part;
                // 尝试从身份证获取性别
                $gender = getGenderFromIdCard($part);
                if ($gender !== '其他') {
                    $result['gender'] = $gender;
                }
            }
            continue;
        }
        
        // 判断是否为性别
        if (preg_match('/^(男|女|M|F)$/i', $part)) {
            $gender_map = ['男' => '男', '女' => '女', 'M' => '男', 'F' => '女'];
            $result['gender'] = $gender_map[strtoupper($part)] ?? '其他';
            continue;
        }
        
        // 判断是否为邮箱
        if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
            if (empty($result['email'])) {
                $result['email'] = $part;
            }
            continue;
        }
        
        // 如果包含字母且长度合适，可能是姓名的一部分
        if (preg_match('/[a-zA-Z\u4e00-\u9fa5]/', $part) && strlen($part) <= 20) {
            // 将其追加到姓名中
            $result['name'] .= ' ' . $part;
            continue;
        }
        
        // 如果都不是，且证件号为空，可能是证件号
        if (empty($result['id_card']) && strlen($part) >= 5) {
            $result['id_card'] = $part;
        }
    }
    
    // 清理姓名（移除多余空格）
    $result['name'] = preg_replace('/\s+/', ' ', trim($result['name']));
    
    // 姓名是必需的，其他字段可以为空
    if (empty($result['name'])) {
        return false;
    }
    
    return $result;
}

// 智能姓名解析函数
function smartNameParsing($parts) {
    if (empty($parts)) return [];
    
    $name_parts = [];
    $other_parts = [];
    $name_ended = false;
    
    foreach ($parts as $part) {
        // 如果遇到明显的非姓名信息，停止合并姓名
        if (preg_match('/^\d{6,}$/', $part) || // 手机号或证件号
            isValidIdCard($part) || // 证件号
            filter_var($part, FILTER_VALIDATE_EMAIL) || // 邮箱
            preg_match('/^(男|女|M|F)$/i', $part)) { // 性别
            $name_ended = true;
        }
        
        if (!$name_ended && preg_match('/^[a-zA-Z\u4e00-\u9fa5]+$/', $part)) {
            // 纯字母或汉字，可能是姓名的一部分
            $name_parts[] = $part;
        } else {
            $name_ended = true;
            $other_parts[] = $part;
        }
    }
    
    $result = [];
    
    // 合并姓名部分
    if (!empty($name_parts)) {
        $result[] = implode(' ', $name_parts);
    }
    
    // 添加其他部分
    $result = array_merge($result, $other_parts);
    
    return $result;
}

// 判断是否为有效证件号码（宽松验证）
function isValidIdCard($id_card) {
    $id_card = trim(strtoupper($id_card));
    
    // 标准身份证
    if (preg_match('/^\d{15}$/', $id_card) || preg_match('/^\d{17}[\dX]$/', $id_card)) {
        return true;
    }
    
    // 护照格式
    if (preg_match('/^[A-Z]\d{7,9}$/', $id_card) || preg_match('/^[A-Z]{1,3}\d{6,12}$/', $id_card)) {
        return true;
    }
    
    // 港澳通行证
    if (preg_match('/^[HMT]\d{8,10}$/', $id_card)) {
        return true;
    }
    
    // 其他数字证件（长度5-20位）
    if (preg_match('/^[A-Z0-9]{5,20}$/', $id_card)) {
        return true;
    }
    
    return false;
}

// 从身份证号获取性别
function getGenderFromIdCard($id_card) {
    $id_card = trim($id_card);
    
    // 18位身份证
    if (preg_match('/^\d{17}[\dX]$/', $id_card)) {
        $gender_digit = substr($id_card, 16, 1);
        return ($gender_digit % 2 == 1) ? '男' : '女';
    }
    
    // 15位身份证
    if (preg_match('/^\d{15}$/', $id_card)) {
        $gender_digit = substr($id_card, 14, 1);
        return ($gender_digit % 2 == 1) ? '男' : '女';
    }
    
    return '其他';
}

// 向后兼容 - 保留原有的复杂解析函数作为备用
function parsePersonnelInfoLegacy($line) {
    // 原有的复杂解析逻辑...
    return false;
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
                <h1 class="mt-4 mb-4">
                    <i class="bi bi-people"></i> 批量添加人员 - 第一步
                </h1>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($project_id <= 0): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> 请先选择项目
                </div>
            <?php elseif (!empty($parsed_personnel)): ?>
                <!-- 显示已识别的人员信息，准备进入第二步 -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">已识别的人员信息 (<?php echo count($parsed_personnel); ?>人)</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="batch_add_personnel_step2.php">
                            <input type="hidden" name="personnel_data" value='<?php echo htmlspecialchars(json_encode($parsed_personnel)); ?>'>
                            <input type="hidden" name="original_personnel_data" value='<?php echo htmlspecialchars($_POST['personnel_data'] ?? ''); ?>'>
                            
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
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
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>智能识别结果：</strong>成功识别 <?php echo count($parsed_personnel); ?> 人的信息。
                                点击"下一步"为这些人员分配部门和职位，缺少的信息可以后续在人员管理中补充。
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-arrow-right"></i> 下一步
                            </button>
                            <!-- 修改为传递原始数据，确保返回时能保留已输入信息 -->
                            <a href="batch_add_personnel.php?return=1&return_data=<?php echo urlencode($_POST['personnel_data'] ?? ''); ?>" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> 返回修改
                            </a>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- 第一步：输入人员信息 -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">项目: <?php echo htmlspecialchars($project_name); ?></h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="parse_personnel">
                            
                            <div class="mb-3">
                                <label class="form-label">人员信息（灵活格式）</label>
                                <!-- 支持从URL参数获取返回数据，确保用户点击返回修改时保留已输入内容 -->
                                <textarea class="form-control" name="personnel_data" rows="12" 
                                          placeholder="支持多种灵活格式，信息可以不完整：

✅ 完整信息格式：
张三,440801199001011234,13800138000
李四 440801199002022345 13900139000
王五,P12345678,13700137000,女

✅ 不完整信息格式（推荐）：
张三  （只有姓名）
李四,13800138000  （姓名+手机）
王五,440801199001011234  （姓名+证件号）
陈六,女,13900139000  （姓名+性别+手机）

✅ 英文姓名支持：
John Smith  （简单英文名）
Polanco Alejo Cristian De Jesus  （复杂英文名）
Maria Elena Gonzalez Rodriguez,P12345678  （英文名+护照）
John Smith,13800138000,男  （英文名+手机+性别）

✅ 国际证件支持：
John Smith,P12345678  （英文名+护照）
刘七,H12345678,13700137000  （港澳通行证）

💡 智能识别规则：
• 英文姓名中的空格会被正确保留
• 6-15位数字自动识别为手机号
• 身份证/护照/港澳证自动识别
• 男/女/M/F自动识别为性别
• 邮箱地址自动识别
• 分隔符：逗号、分号优先，空格作为后备"
                                          required><?php echo htmlspecialchars($_POST['personnel_data'] ?? $_GET['return_data'] ?? ''); ?></textarea>
                                <div class="form-text">
                                <strong>🌟 新功能亮点：</strong><br>
                                • <span class="text-success">✅ 信息不完整也能添加</span> - 只需要姓名即可，其他信息可后续补充<br>
                                • <span class="text-success">✅ 智能英文姓名识别</span> - 正确处理带空格的英文姓名，如 "Polanco Alejo Cristian De Jesus"<br>
                                • <span class="text-success">✅ 智能格式识别</span> - 自动识别手机号、证件号、性别、邮箱<br>
                                • <span class="text-success">✅ 多种分隔符支持</span> - 逗号、分号优先，智能处理空格分隔<br>
                                • <span class="text-success">✅ 国际化支持</span> - 支持中英文姓名、护照、港澳通行证<br>
                                <br>
                                <strong>💡 使用建议：</strong><br>
                                • 英文姓名：直接输入即可，空格会被正确保留<br>
                                • 复杂信息：建议用逗号分隔，更准确<br>
                                • 缺失信息：直接输入姓名即可，系统会创建基础档案
                            </div>
                            </div>

                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>提示：</strong>系统会自动识别人员信息，点击"下一步"后将为这些人员分配部门和职位
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-arrow-right"></i> 下一步
                            </button>
                            <a href="personnel.php" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> 取消
                            </a>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
<?php
// 包含页脚文件
include 'includes/footer.php';
?>