<?php
/**
 * 增强版中文转拼音函数库
 * 支持多音字处理、声调选项、首字母提取等功能
 */

/**
 * 中文转拼音主函数
 * 
 * @param string $chinese 中文字符串
 * @param array $options 可选配置项
 * @return string 转换后的拼音字符串
 */
function chineseToPinyin($chinese, $options = []) {
    // 默认配置
    $defaults = [
        'delimiter' => ' ',      // 拼音分隔符
        'onlyFirstLetter' => false, // 是否只返回首字母
        'withTone' => false,     // 是否保留声调
        'polyphone' => false,    // 是否启用多音字处理
        'ignoreNonChinese' => false, // 是否忽略非中文字符
        'upperFirst' => true     // 首字母是否大写
    ];
    
    // 合并配置
    $config = array_merge($defaults, $options);
    
    // 初始化结果
    $pinyin = '';
    
    // 获取字符串长度
    $len = mb_strlen($chinese, 'UTF-8');
    
    // 上一个字符是否为中文
    $lastIsChinese = false;
    
    // 逐个处理字符
    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($chinese, $i, 1, 'UTF-8');
        $isChinese = isChineseChar($char);
        
        // 检查是否为中文字符
        if ($isChinese) {
            // 如果上一个字符是中文且分隔符不为空，添加分隔符
            if ($lastIsChinese && $config['delimiter'] !== '' && $pinyin !== '') {
                $pinyin .= $config['delimiter'];
            }
            
            // 获取拼音
            $charPinyin = getCharPinyin($char, $config);
            $pinyin .= $charPinyin;
            $lastIsChinese = true;
        } else {
            // 非中文字符处理
            if ($config['ignoreNonChinese']) {
                continue;
            } else {
                // 如果当前是空格，且前后都是中文，则添加分隔符
                if ($char === ' ' && $lastIsChinese && $i + 1 < $len && isChineseChar(mb_substr($chinese, $i + 1, 1, 'UTF-8'))) {
                    $pinyin .= $config['delimiter'];
                } else {
                    $pinyin .= $char;
                }
                $lastIsChinese = false;
            }
        }
    }
    
    return $pinyin;
}

/**
 * 检查是否为中文字符
 * 
 * @param string $char 单个字符
 * @return bool 是否为中文字符
 */
function isChineseChar($char) {
    return preg_match('/[\x{4e00}-\x{9fa5}]/u', $char) === 1;
}

/**
 * 加载标准拼音映射表
 * 
 * @return array 标准拼音映射表
 */
function loadStandardPinyinMap() {
    // 初始化空的标准拼音映射数组
    $standardPinyinMap = [];
    
    // 尝试从文件加载映射
    $filePath = __DIR__ . '/standard_pinyin_map.txt';
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        if (!empty($content)) {
            $lines = preg_split('/\r\n|\r|\n/', $content); // 更可靠地分割行
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue; // 跳过空行和注释行
                }
                
                $parts = explode('=', $line);
                if (count($parts) === 2) {
                    $char = trim($parts[0]);
                    $pinyin = trim($parts[1]);
                    if (!empty($char) && !empty($pinyin)) {
                        $standardPinyinMap[$char] = [$pinyin, strtolower($pinyin)];
                    }
                }
            }
        }
    }
    
    return $standardPinyinMap;
}

/**
 * 获取单个中文字符的拼音
 * 
 * @param string $char 单个中文字符
 * @param array $config 配置项
 * @return string 拼音
 */
function getCharPinyin($char, $config) {
    // 加载标准拼音映射表
    $standardPinyinMap = loadStandardPinyinMap();
    
    // 如果不是中文字符，直接返回原字符
    if (!isChineseChar($char)) {
        return $char;
    }
    
    // 查找拼音映射
    if (isset($standardPinyinMap[$char])) {
        $index = $config['upperFirst'] ? 0 : 1;
        return $standardPinyinMap[$char][$index];
    }
    
    // 记录未找到拼音映射的汉字
    $logFile = __DIR__ . '/../logs/missing_pinyin.log';
    $logDir = dirname($logFile);
    
    // 确保日志目录存在
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // 记录日志，包含时间戳和上下文信息
    $logMessage = date('Y-m-d H:i:s') . " - 未找到汉字 '{$char}' 的拼音映射\n";
    error_log($logMessage, 3, $logFile);
    
    // 返回原字符
    return $char;
}

/**
 * 加载香港拼音映射表
 * 
 * @return array 香港拼音映射表
 */
function loadHongKongPinyinMap() {
    // 初始化空的香港拼音映射数组
    $hkPinyinMap = [];
    
    // 尝试从文件加载映射
    $filePath = __DIR__ . '/hk_pinyin_map.txt';
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        if (!empty($content)) {
            $lines = preg_split('/\r\n|\r|\n/', $content); // 更可靠地分割行
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue; // 跳过空行和注释行
                }
                
                $parts = explode('=', $line);
                if (count($parts) === 2) {
                    $char = trim($parts[0]);
                    $pinyin = trim($parts[1]);
                    if (!empty($char) && !empty($pinyin)) {
                        // 处理包含"/"的拼音，如"德=Dak/De"
                        if (strpos($pinyin, '/') !== false) {
                            $pinyinParts = explode('/', $pinyin);
                            $hkPinyinMap[$char] = [$pinyinParts[0], strtolower($pinyinParts[1])];
                        } else {
                            $hkPinyinMap[$char] = [$pinyin, strtolower($pinyin)];
                        }
                    }
                }
            }
        }
    }
    
    return $hkPinyinMap;
}

/**
 * 中文转香港拼音
 * 
 * @param string $chinese 中文字符串
 * @param array $options 可选配置项
 * @return string 转换后的香港拼音字符串
 */
function chineseToHongKongPinyin($chinese, $options = []) {
    // 默认配置
    $defaults = [
        'delimiter' => ' ',      // 拼音分隔符
        'onlyFirstLetter' => false, // 是否只返回首字母
        'ignoreNonChinese' => false, // 是否忽略非中文字符
        'upperFirst' => true     // 首字母是否大写
    ];
    
    // 合并配置
    $config = array_merge($defaults, $options);
    
    // 加载香港拼音映射表
    $hkPinyinMap = loadHongKongPinyinMap();
    
    // 初始化结果
    $pinyin = '';
    
    // 获取字符串长度
    $len = mb_strlen($chinese, 'UTF-8');
    
    // 上一个字符是否为中文
    $lastIsChinese = false;
    
    // 逐个处理字符
    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($chinese, $i, 1, 'UTF-8');
        $isChinese = isChineseChar($char);
        
        // 检查是否为中文字符
        if ($isChinese) {
            // 如果上一个字符是中文且分隔符不为空，添加分隔符
            if ($lastIsChinese && $config['delimiter'] !== '' && $pinyin !== '') {
                $pinyin .= $config['delimiter'];
            }
            
            // 获取香港拼音
            if (isset($hkPinyinMap[$char])) {
                $index = $config['upperFirst'] ? 0 : 1;
                $charPinyin = $hkPinyinMap[$char][$index];
            } else {
                // 如果没有香港拼音映射，回退到标准拼音
                $charPinyin = getCharPinyin($char, $config);
            }
            
            // 如果只需要首字母
            if ($config['onlyFirstLetter']) {
                $charPinyin = mb_substr($charPinyin, 0, 1, 'UTF-8');
            }
            
            $pinyin .= $charPinyin;
            $lastIsChinese = true;
        } else {
            // 非中文字符处理
            if ($config['ignoreNonChinese']) {
                continue;
            } else {
                // 如果当前是空格，且前后都是中文，则添加分隔符
                if ($char === ' ' && $lastIsChinese && $i + 1 < $len && isChineseChar(mb_substr($chinese, $i + 1, 1, 'UTF-8'))) {
                    $pinyin .= $config['delimiter'];
                } else {
                    $pinyin .= $char;
                }
                $lastIsChinese = false;
            }
        }
    }
    
    return $pinyin;
}

/**
 * 判断是否为中国大陆身份证
 * 
 * @param string $idCard 证件号码
 * @return bool 是否为中国大陆身份证
 */
function isMainlandIdCard($idCard) {
    // 中国大陆身份证为18位，前17位为数字，最后一位为数字或X
    if (strlen($idCard) !== 18) {
        return false;
    }
    
    // 检查前17位是否都是数字
    if (!is_numeric(substr($idCard, 0, 17))) {
        return false;
    }
    
    // 检查最后一位是否为数字或X
    $lastChar = strtoupper(substr($idCard, 17, 1));
    if (!is_numeric($lastChar) && $lastChar !== 'X') {
        return false;
    }
    
    // 验证身份证校验码
    $weights = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
    $checksum = ['1', '0', 'X', '9', '8', '7', '6', '5', '4', '3', '2'];
    
    $sum = 0;
    for ($i = 0; $i < 17; $i++) {
        $sum += intval($idCard[$i]) * $weights[$i];
    }
    
    $mod = $sum % 11;
    return $lastChar === $checksum[$mod];
}

/**
 * 判断是否为港澳居民来往内地通行证（回乡证）
 * 
 * @param string $idCard 证件号码
 * @return bool 是否为港澳通行证
 */
function isHongKongMacauPass($idCard) {
    // 港澳通行证格式：任意大写英文字母开头 + 数字（根据用户需求调整）
    if (empty($idCard)) {
        return false;
    }
    
    // 检查是否以大写英文字母开头
    $firstChar = substr($idCard, 0, 1);
    if (!ctype_upper($firstChar)) {
        return false;
    }
    
    // 检查剩余部分是否为数字
    $remaining = substr($idCard, 1);
    if (!is_numeric($remaining)) {
        return false;
    }
    
    // 长度要求：至少包含1个字母+1个数字
    $length = strlen($idCard);
    return ($length >= 2 && $length <= 20); // 放宽长度限制，适应各种可能的证件格式
}

/**
 * 判断是否为台湾居民来往大陆通行证
 * 
 * @param string $idCard 证件号码
 * @return bool 是否为台湾通行证
 */
function isTaiwanPass($idCard) {
    // 台湾通行证格式：多位数字，通常10位或更多
    if (empty($idCard)) {
        return false;
    }
    
    // 检查是否全为数字
    return is_numeric($idCard) && strlen($idCard) >= 8;
}

/**
 * 判断是否为护照
 * 
 * @param string $idCard 证件号码
 * @return bool 是否为护照
 */
function isPassport($idCard) {
    // 护照格式：1位字母 + 8位数字 或 全数字（旧版）或 其他格式
    if (empty($idCard)) {
        return false;
    }
    
    $length = strlen($idCard);
    
    // 如果长度为9位，检查是否为1位字母+8位数字
    if ($length === 9) {
        $firstChar = substr($idCard, 0, 1);
        $remaining = substr($idCard, 1);
        return ctype_alpha($firstChar) && is_numeric($remaining);
    }
    
    // 中国护照格式通常是字母开头后跟数字，或者全数字
    // 常见格式：E + 8位数字，G + 8位数字，S + 8位数字等
    if ($length >= 8 && $length <= 12) {
        // 检查是否以常见护照字母开头
        $firstChar = strtoupper(substr($idCard, 0, 1));
        $passportPrefixes = ['E', 'G', 'S', 'P', 'D', 'C', 'H', 'J', 'K', 'L', 'M', 'N', 'Q', 'R', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];
        
        if (in_array($firstChar, $passportPrefixes)) {
            $remaining = substr($idCard, 1);
            return is_numeric($remaining);
        }
        
        // 如果全是数字，也可能是护照
        return is_numeric($idCard);
    }
    
    return false;
}

/**
 * 根据证件类型获取拼音
 * 
 * @param string $name 姓名
 * @param string $docNo 证件号码
 * @return string 拼音
 */
function getPinyinByDocType($name, $docNo = '') {
    // 检查是否包含中文字符
    $hasChinese = false;
    for ($i = 0; $i < mb_strlen($name, 'UTF-8'); $i++) {
        if (isChineseChar(mb_substr($name, $i, 1, 'UTF-8'))) {
            $hasChinese = true;
            break;
        }
    }
    
    // 如果不包含中文字符，直接返回空字符串
    if (!$hasChinese) {
        return '';
    }

    // 拼音转换配置
    $options = [
        'delimiter' => '', 
        'upperFirst' => true,
        'ignoreNonChinese' => false
    ];

    // 根据证件类型选择转换方法
    $pinyinType = 'standard'; // 默认使用标准拼音
    
    if (isMainlandIdCard($docNo)) {
        // 大陆身份证使用标准拼音
        $pinyinType = 'standard';
    } elseif (isHongKongMacauPass($docNo)) {
        // 港澳通行证使用香港拼音
        $pinyinType = 'hongkong';
    } elseif (isTaiwanPass($docNo)) {
        // 台湾通行证使用标准拼音
        $pinyinType = 'standard';
    } elseif (isPassport($docNo)) {
        // 护照使用标准拼音
        $pinyinType = 'standard';
    }
    
    // 根据拼音类型选择转换方法
    $result = ($pinyinType === 'hongkong') ? 
        chineseToHongKongPinyin($name, $options) : 
        chineseToPinyin($name, $options);

    // 检查转换结果，如果还有中文字符则进行补充转换
    $resultArr = [];
    for ($i = 0; $i < mb_strlen($name, 'UTF-8'); $i++) {
        $char = mb_substr($name, $i, 1, 'UTF-8');
        if (isChineseChar($char)) {
            // 根据拼音类型选择映射表
            if ($pinyinType === 'hongkong') {
                $hkPinyinMap = loadHongKongPinyinMap();
                if (isset($hkPinyinMap[$char])) {
                    $resultArr[] = $hkPinyinMap[$char][0];
                    continue;
                }
            } else {
                // 回退到标准拼音
                $standardPinyinMap = loadStandardPinyinMap();
                if (isset($standardPinyinMap[$char])) {
                    $resultArr[] = $standardPinyinMap[$char][0];
                } else {
                    $resultArr[] = '?';
                }
            }
        } else {
            $resultArr[] = $char;
        }
    }

    return implode('', $resultArr);
}