<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس بهینه‌سازی فایل‌های CSS و JavaScript
 */
class ASG_Assets_Optimizer {
    private static $instance = null;
    private $settings;
    private $cache_dir;
    private $css_files = array();
    private $js_files = array();

    /**
     * دریافت نمونه کلاس (الگوی Singleton)
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * سازنده کلاس
     */
    public function __construct() {
        $this->settings = get_option('asg_settings', array());
        $upload_dir = wp_upload_dir();
        $this->cache_dir = $upload_dir['basedir'] . '/asg-cache';

        // ایجاد دایرکتوری کش اگر وجود نداشت
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }

        $this->init_hooks();
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function init_hooks() {
        // فقط در صورت فعال بودن بهینه‌سازی
        if (!empty($this->settings['optimize_assets'])) {
            add_action('wp_enqueue_scripts', array($this, 'register_optimized_assets'), 999);
            add_action('admin_enqueue_scripts', array($this, 'register_optimized_assets'), 999);
            add_action('wp_footer', array($this, 'maybe_optimize_assets'), 999);
        }
    }

    /**
     * ثبت فایل‌های بهینه‌شده
     */
    public function register_optimized_assets() {
        // ثبت فایل‌های CSS بهینه‌شده
        $this->css_files = array(
            'asg-admin' => ASG_PLUGIN_URL . 'assets/css/asg-admin.css',
            'asg-public' => ASG_PLUGIN_URL . 'assets/css/asg-public.css',
            'asg-reports' => ASG_PLUGIN_URL . 'assets/css/asg-reports.css',
            'asg-style' => ASG_PLUGIN_URL . 'assets/css/asg-style.css'
        );

        // ثبت فایل‌های JS بهینه‌شده
        $this->js_files = array(
            'asg-script' => ASG_PLUGIN_URL . 'assets/js/asg-script.js',
            'asg-reports' => ASG_PLUGIN_URL . 'assets/js/asg-reports.js',
            'warranty-form' => ASG_PLUGIN_URL . 'assets/js/modules/warranty-form.js',
            'reports' => ASG_PLUGIN_URL . 'assets/js/modules/reports.js',
            'error-handler' => ASG_PLUGIN_URL . 'assets/js/modules/error-handler.js'
        );
    }

    /**
     * اجرای بهینه‌سازی فایل‌ها در صورت نیاز
     */
    public function maybe_optimize_assets() {
        $css_hash = $this->get_files_hash($this->css_files);
        $js_hash = $this->get_files_hash($this->js_files);
        
        $css_cache_file = $this->cache_dir . '/optimized-' . $css_hash . '.css';
        $js_cache_file = $this->cache_dir . '/optimized-' . $js_hash . '.js';

        // بررسی و بهینه‌سازی CSS
        if (!file_exists($css_cache_file)) {
            $this->optimize_css_files($css_cache_file);
        }

        // بررسی و بهینه‌سازی JS
        if (!file_exists($js_cache_file)) {
            $this->optimize_js_files($js_cache_file);
        }
    }

    /**
     * محاسبه هش فایل‌ها
     */
    private function get_files_hash($files) {
        $content = '';
        foreach ($files as $file) {
            $file_path = str_replace(ASG_PLUGIN_URL, ASG_PLUGIN_DIR, $file);
            if (file_exists($file_path)) {
                $content .= filemtime($file_path);
            }
        }
        return md5($content);
    }

    /**
     * بهینه‌سازی فایل‌های CSS
     */
    private function optimize_css_files($cache_file) {
        $combined_css = '';
        
        foreach ($this->css_files as $handle => $file) {
            $file_path = str_replace(ASG_PLUGIN_URL, ASG_PLUGIN_DIR, $file);
            if (file_exists($file_path)) {
                $css = file_get_contents($file_path);
                
                // حذف کامنت‌ها
                $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
                
                // حذف فضاهای خالی
                $css = str_replace(array("\r\n", "\r", "\n", "\t"), '', $css);
                $css = preg_replace('/\s+/', ' ', $css);
                
                $combined_css .= $css;
            }
        }

        // ذخیره فایل بهینه‌شده
        file_put_contents($cache_file, $combined_css);
        
        // ایجاد نسخه مینیفای شده
        $min_file = str_replace('.css', '.min.css', $cache_file);
        file_put_contents($min_file, $combined_css);
    }

    /**
     * بهینه‌سازی فایل‌های JavaScript
     */
    private function optimize_js_files($cache_file) {
        $combined_js = '';
        
        foreach ($this->js_files as $handle => $file) {
            $file_path = str_replace(ASG_PLUGIN_URL, ASG_PLUGIN_DIR, $file);
            if (file_exists($file_path)) {
                $js = file_get_contents($file_path);
                
                // حذف کامنت‌ها
                $js = preg_replace('/(?:(?:\/\*(?:[^*]|(?:\*+[^*\/]))*\*+\/)|(?:(?<!\:|\\\|\'|\")\/\/.*))/', '', $js);
                
                // حذف فضاهای خالی
                $js = preg_replace('/\s+/', ' ', $js);
                
                $combined_js .= $js . ";\n";
            }
        }

        // ذخیره فایل بهینه‌شده
        file_put_contents($cache_file, $combined_js);
        
        // ایجاد نسخه مینیفای شده
        $min_file = str_replace('.js', '.min.js', $cache_file);
        file_put_contents($min_file, $combined_js);
    }

    /**
     * پاکسازی فایل‌های کش
     */
    public function clear_cache() {
        $files = glob($this->cache_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        return true;
    }

    /**
     * دریافت آدرس فایل‌های بهینه‌شده
     */
    public function get_optimized_urls() {
        $css_hash = $this->get_files_hash($this->css_files);
        $js_hash = $this->get_files_hash($this->js_files);
        
        $upload_dir = wp_upload_dir();
        
        return array(
            'css' => $upload_dir['baseurl'] . '/asg-cache/optimized-' . $css_hash . '.min.css',
            'js' => $upload_dir['baseurl'] . '/asg-cache/optimized-' . $js_hash . '.min.js'
        );
    }

    /**
     * بررسی وجود فایل‌های بهینه‌شده
     */
    public function has_optimized_files() {
        $css_hash = $this->get_files_hash($this->css_files);
        $js_hash = $this->get_files_hash($this->js_files);
        
        $css_exists = file_exists($this->cache_dir . '/optimized-' . $css_hash . '.min.css');
        $js_exists = file_exists($this->cache_dir . '/optimized-' . $js_hash . '.min.js');
        
        return $css_exists && $js_exists;
    }
}