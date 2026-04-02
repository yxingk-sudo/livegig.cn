<?php
/**
 * 网站配置管理类
 * 用于统一管理网站的全局配置
 */

// 确保数据库类已加载
require_once __DIR__ . '/../config/database.php';

class SiteConfig {
    private $db;
    private $config_cache = [];
    
    public function __construct($database = null) {
        if ($database) {
            $this->db = $database->getConnection();
        } else {
            $database = new Database();
            $this->db = $database->getConnection();
        }
        
        $this->loadAllConfigs();
    }
    
    /**
     * 加载所有配置到缓存
     */
    private function loadAllConfigs() {
        try {
            $query = "SELECT config_key, config_value FROM site_config";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($configs as $config) {
                $this->config_cache[$config['config_key']] = $config['config_value'];
            }
        } catch (Exception $e) {
            // 如果表不存在，使用默认配置
            $this->setDefaultConfigs();
        }
    }
    
    /**
     * 设置默认配置
     */
    private function setDefaultConfigs() {
        $this->config_cache = [
            'site_name' => '团队接待处理系统',
            'site_url' => 'http://local.livegig.cn',
            'admin_title' => '团队接待后台管理系统',
            'frontend_title' => '团队接待处理系统 - 欢迎使用',
            'meta_description' => '专业的团队接待处理系统，提供报餐、报酒店、报出行车等一站式服务',
            'meta_keywords' => '团队接待,报餐系统,酒店管理,出行车管理',
            'contact_email' => 'admin@livegig.cn',
            'contact_phone' => '400-123-4567',
            'footer_text' => '© 2024 团队接待处理系统. All rights reserved.',
            'logo_text' => '团队接待',
            'enable_registration' => '1',
            'maintenance_mode' => '0'
        ];
    }
    
    /**
     * 获取配置值
     */
    public function get($key, $default = null) {
        return $this->config_cache[$key] ?? $default;
    }
    
    /**
     * 设置配置值
     */
    public function set($key, $value) {
        try {
            $query = "UPDATE site_config SET config_value = :value WHERE config_key = :key";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':value', $value);
            $stmt->bindParam(':key', $key);
            $stmt->execute();
            
            // 更新缓存
            $this->config_cache[$key] = $value;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * 获取网站标题
     */
    public function getSiteTitle() {
        return $this->get('site_name', '团队接待处理系统');
    }
    
    /**
     * 获取网站URL
     */
    public function getSiteUrl() {
        $url = $this->get('site_url', 'http://local.livegig.cn');
        // 确保URL以/结尾
        return rtrim($url, '/') . '/';
    }
    
    /**
     * 获取管理后台标题
     */
    public function getAdminTitle() {
        return $this->get('admin_title', '团队接待后台管理系统');
    }
    
    /**
     * 获取前端标题
     */
    public function getFrontendTitle() {
        return $this->get('frontend_title', '团队接待处理系统 - 欢迎使用');
    }
    
    /**
     * 获取完整的网站信息
     */
    public function getSiteInfo() {
        return [
            'site_name' => $this->getSiteTitle(),
            'site_url' => $this->getSiteUrl(),
            'admin_title' => $this->getAdminTitle(),
            'frontend_title' => $this->getFrontendTitle(),
            'meta_description' => $this->get('meta_description', ''),
            'meta_keywords' => $this->get('meta_keywords', ''),
            'contact_email' => $this->get('contact_email', ''),
            'contact_phone' => $this->get('contact_phone', ''),
            'footer_text' => $this->get('footer_text', ''),
            'logo_text' => $this->get('logo_text', ''),
            'enable_registration' => $this->get('enable_registration') === '1',
            'maintenance_mode' => $this->get('maintenance_mode') === '1'
        ];
    }
    
    /**
     * 检查是否处于维护模式
     */
    public function isMaintenanceMode() {
        return $this->get('maintenance_mode') === '1';
    }
    
    /**
     * 获取当前页面标题
     */
    public function getPageTitle($page_name = '') {
        $site_name = $this->getSiteTitle();
        if (empty($page_name)) {
            return $site_name;
        }
        return $page_name . ' - ' . $site_name;
    }
    
    /**
     * 生成完整的网站URL
     */
    public function generateUrl($path = '') {
        $base_url = $this->getSiteUrl();
        $path = ltrim($path, '/');
        return $base_url . $path;
    }
}

/**
 * 全局配置函数
 * 简化配置获取
 */
function get_site_config($key, $default = null) {
    static $site_config = null;
    if ($site_config === null) {
        require_once __DIR__ . '/../config/database.php';
        $site_config = new SiteConfig();
    }
    return $site_config->get($key, $default);
}

function site_name() {
    return get_site_config('site_name', '团队接待处理系统');
}

function site_url($path = '') {
    static $site_config = null;
    if ($site_config === null) {
        require_once __DIR__ . '/../config/database.php';
        $site_config = new SiteConfig();
    }
    return $site_config->generateUrl($path);
}

function admin_title() {
    return get_site_config('admin_title', '团队接待后台管理系统');
}

function frontend_title() {
    return get_site_config('frontend_title', '团队接待处理系统 - 欢迎使用');
}
?>