<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/pinyin_functions.php';

// 启动session
session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

// 检查是否有权限执行此操作
if (!isset($_SESSION['user_id']) || !isset($_SESSION['project_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 处理POST请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 读取日志文件，找出缺失的汉字
        $logFile = __DIR__ . '/../logs/missing_pinyin.log';
        $missingChars = [];
        
        if (file_exists($logFile)) {
            $content = file_get_contents($logFile);
            if (!empty($content)) {
                // 使用正则表达式提取所有缺失的汉字
                preg_match_all("/未找到汉字 '([^']+)' 的拼音映射/", $content, $matches);
                if (!empty($matches[1])) {
                    $missingChars = array_unique($matches[1]);
                }
            }
        }
        
        // 如果没有缺失的字符，直接返回
        if (empty($missingChars)) {
            echo json_encode(['success' => true, 'message' => '没有发现缺失的拼音映射', 'added_chars' => []]);
            exit;
        }
        
        // 获取现有的hk_pinyin_map.txt内容
        $hkPinyinFile = __DIR__ . '/../includes/hk_pinyin_map.txt';
        $existingHKContent = '';
        $existingHKChars = [];
        
        if (file_exists($hkPinyinFile)) {
            $existingHKContent = file_get_contents($hkPinyinFile);
            // 提取已存在的字符
            preg_match_all("/^([^=#\s][^=]*)=/m", $existingHKContent, $existingMatches);
            if (!empty($existingMatches[1])) {
                $existingHKChars = array_flip($existingMatches[1]); // 使用flip以便快速查找
            }
        }
        
        // 获取现有的standard_pinyin_map.txt内容
        $standardPinyinFile = __DIR__ . '/../includes/standard_pinyin_map.txt';
        $existingStandardContent = '';
        $existingStandardChars = [];
        
        if (file_exists($standardPinyinFile)) {
            $existingStandardContent = file_get_contents($standardPinyinFile);
            // 提取已存在的字符
            preg_match_all("/^([^=#\s][^=]*)=/m", $existingStandardContent, $existingMatches);
            if (!empty($existingMatches[1])) {
                $existingStandardChars = array_flip($existingMatches[1]); // 使用flip以便快速查找
            }
        }
        
        // 准备要添加的新映射
        $newHKMappings = [];
        $newStandardMappings = [];
        $addedChars = [];
        
        // 加载标准拼音映射表
        $standardPinyinMap = loadStandardPinyinMap();
        // 加载香港拼音映射表作为补充
        $hkPinyinMap = loadHongKongPinyinMap();
        
        foreach ($missingChars as $char) {
            // 获取拼音作为默认值
            $defaultPinyin = null;
            
            // 1. 首先尝试从标准拼音映射表获取
            if (isset($standardPinyinMap[$char])) {
                $defaultPinyin = $standardPinyinMap[$char][0]; // 返回大写形式的拼音
            }
            // 2. 其次尝试从香港拼音映射表获取
            else if (isset($hkPinyinMap[$char])) {
                $defaultPinyin = $hkPinyinMap[$char][0]; // 返回大写形式的拼音
            }
            // 3. 最后尝试使用默认规则生成拼音
            else {
                $defaultPinyin = generateDefaultPinyin($char);
            }
            
            // 如果找到了拼音，添加到映射文件中
            if ($defaultPinyin) {
                // 如果HK映射中不存在该字符，则添加到HK映射
                if (!isset($existingHKChars[$char])) {
                    $newHKMappings[] = "$char=$defaultPinyin";
                }
                
                // 如果Standard映射中不存在该字符，则添加到Standard映射
                if (!isset($existingStandardChars[$char])) {
                    $newStandardMappings[] = "$char=$defaultPinyin";
                }
                
                // 只要有一个映射文件添加了该字符，就记录为已添加
                if (!isset($existingHKChars[$char]) || !isset($existingStandardChars[$char])) {
                    $addedChars[] = $char;
                }
            }
            // 如果仍然没有找到拼音，可以考虑使用字符本身作为临时解决方案
            else {
                // 使用字符本身作为拼音（临时解决方案）
                $defaultPinyin = $char;
                
                // 添加到映射文件中
                if (!isset($existingHKChars[$char])) {
                    $newHKMappings[] = "$char=$defaultPinyin";
                }
                
                if (!isset($existingStandardChars[$char])) {
                    $newStandardMappings[] = "$char=$defaultPinyin";
                }
                
                if (!isset($existingHKChars[$char]) || !isset($existingStandardChars[$char])) {
                    $addedChars[] = $char;
                }
            }
        }
        
        // 如果有新映射需要添加到hk_pinyin_map.txt
        if (!empty($newHKMappings)) {
            // 在文件末尾添加新映射
            $newHKContent = $existingHKContent;
            if (!empty($newHKContent) && substr($newHKContent, -1) !== "\n") {
                $newHKContent .= "\n";
            }
            
            // 添加注释和新映射
            $newHKContent .= "\n# 一键补全添加的拼音映射 (" . date('Y-m-d H:i:s') . ")\n";
            $newHKContent .= implode("\n", $newHKMappings) . "\n";
            
            // 写入文件
            if (file_put_contents($hkPinyinFile, $newHKContent) === false) {
                throw new Exception('无法写入hk_pinyin_map.txt文件');
            }
        }
        
        // 如果有新映射需要添加到standard_pinyin_map.txt
        if (!empty($newStandardMappings)) {
            // 在文件末尾添加新映射
            $newStandardContent = $existingStandardContent;
            if (!empty($newStandardContent) && substr($newStandardContent, -1) !== "\n") {
                $newStandardContent .= "\n";
            }
            
            // 添加注释和新映射
            $newStandardContent .= "\n# 一键补全添加的拼音映射 (" . date('Y-m-d H:i:s') . ")\n";
            $newStandardContent .= implode("\n", $newStandardMappings) . "\n";
            
            // 写入文件
            if (file_put_contents($standardPinyinFile, $newStandardContent) === false) {
                throw new Exception('无法写入standard_pinyin_map.txt文件');
            }
        }
        
        // 清空日志文件
        file_put_contents($logFile, '');
        
        echo json_encode([
            'success' => true, 
            'message' => '成功补全拼音映射', 
            'added_count' => count($addedChars),
            'added_chars' => $addedChars,
            'hk_added_count' => count($newHKMappings),
            'standard_added_count' => count($newStandardMappings),
            'missing_chars_count' => count($missingChars)
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => '操作失败: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * 生成默认拼音
 * 注意：此函数不应该包含硬编码的拼音映射，而应该由外部文件提供映射
 * 当前实现：如果在标准拼音映射表中找不到拼音，则返回null
 * 后续可以考虑集成更完整的拼音库来增强此功能
 */
function generateDefaultPinyin($char) {
    // 根据用户要求，此函数不应包含硬编码的拼音映射
    // 拼音映射应由外部的hk_pinyin_map.txt和standard_pinyin_map.txt文件提供
    
    // 如果需要增强此功能，可以考虑集成第三方拼音库
    
    // 如果没有找到拼音，返回null
    return null;
}

// 不支持GET请求
http_response_code(405);
echo json_encode(['success' => false, 'message' => '不支持的请求方法']);