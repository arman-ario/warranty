<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت کش افزونه
 */
class ASG_Cache {
    private static $instance = null;
    private $cache_dir;
    private $cache_time = 3600; // یک ساعت
    private $settings;

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

        // ایجاد دایرکتوری کش
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }

        // تنظیم زمان کش از تنظیمات
        if (!empty($this->settings['cache_time'])) {
            $this->cache_time = intval($this->settings['cache_time']) * 60; // تبدیل دقیقه به ثانیه
        }

        $this->init_hooks();
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function init_hooks() {
        // پاکسازی خودکار کش
        add_action('asg_daily_cleanup', array($this, 'auto_cleanup'));
        
        // هوک اکشن‌های مختلف برای پاکسازی کش
        $this->register_cache_cleanup_hooks();

        // اگر کش غیرفعال است، کل کش را پاک کن
        if (empty($this->settings['enable_cache'])) {
            $this->clear_all();
        }
    }

    /**
     * ثبت هوک‌های پاکسازی کش
     */
    private function register_cache_cleanup_hooks() {
        $actions = array(
            'save_post',
            'deleted_post',
            'switched_theme',
            'wp_update_nav_menu',
            'update_option_permalink_structure',
            'updated_option'
        );

        foreach ($actions as $action) {
            add_action($action, array($this, 'clear_all'));
        }

        // هوک‌های مخصوص افزونه
        add_action('asg_warranty_created', array($this, 'clear_warranties_cache'));
        add_action('asg_warranty_updated', array($this, 'clear_warranties_cache'));
        add_action('asg_warranty_deleted', array($this, 'clear_warranties_cache'));
    }

    /**
     * ذخیره داده در کش
     */
    public function set($key, $data, $group = 'default', $expire = null) {
        if (empty($this->settings['enable_cache'])) {
            return false;
        }

        $cache_file = $this->get_cache_file_path($key, $group);
        $expire = is_null($expire) ? time() + $this->cache_time : time() + $expire;

        $cache_data = array(
            'data' => $data,
            'expire' => $expire
        );

        return file_put_contents($cache_file, serialize($cache_data)) !== false;
    }

    /**
     * دریافت داده از کش
     */
    public function get($key, $group = 'default') {
        if (empty($this->settings['enable_cache'])) {
            return false;
        }

        $cache_file = $this->get_cache_file_path($key, $group);

        if (!file_exists($cache_file)) {
            return false;
        }

        $cache_data = unserialize(file_get_contents($cache_file));

        if (!is_array($cache_data) || !isset($cache_data['expire']) || !isset($cache_data['data'])) {
            return false;
        }

        if (time() > $cache_data['expire']) {
            unlink($cache_file);
            return false;
        }

        return $cache_data['data'];
    }

    /**
     * حذف یک آیتم از کش
     */
    public function delete($key, $group = 'default') {
        $cache_file = $this->get_cache_file_path($key, $group);
        
        if (file_exists($cache_file)) {
            return unlink($cache_file);
        }
        
        return false;
    }

    /**
     * پاکسازی کل کش
     */
    public function clear_all() {
        $files = glob($this->cache_dir . '/*');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        return true;
    }

    /**
     * پاکسازی کش گارانتی‌ها
     */
    public function clear_warranties_cache() {
        $files = glob($this->cache_dir . '/warranties-*');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        
        return true;
    }

    /**
     * پاکسازی خودکار کش
     */
    public function auto_cleanup() {
        $files = glob($this->cache_dir . '/*');
        $now = time();
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $cache_data = unserialize(file_get_contents($file));
                
                if (!is_array($cache_data) || 
                    !isset($cache_data['expire']) || 
                    $now > $cache_data['expire']) {
                    unlink($file);
                }
            }
        }
        
        return true;
    }

    /**
     * دریافت مسیر فایل کش
     */
    private function get_cache_file_path($key, $group) {
        $key = preg_replace('/[^a-z0-9_-]/i', '', $key);
        $group = preg_replace('/[^a-z0-9_-]/i', '', $group);
        return $this->cache_dir . '/' . $group . '-' . $key . '.cache';
    }

    /**
     * بررسی وجود داده در کش
     */
    public function has($key, $group = 'default') {
        if (empty($this->settings['enable_cache'])) {
            return false;
        }

        $cache_file = $this->get_cache_file_path($key, $group);

        if (!file_exists($cache_file)) {
            return false;
        }

        $cache_data = unserialize(file_get_contents($cache_file));

        if (!is_array($cache_data) || !isset($cache_data['expire'])) {
            return false;
        }

        return time() <= $cache_data['expire'];
    }

    /**
     * دریافت آمار کش
     */
    public function get_stats() {
        $files = glob($this->cache_dir . '/*');
        $total_size = 0;
        $file_count = 0;
        $expired_count = 0;
        $now = time();

        foreach ($files as $file) {
            if (is_file($file)) {
                $file_count++;
                $total_size += filesize($file);

                $cache_data = unserialize(file_get_contents($file));
                if (is_array($cache_data) && isset($cache_data['expire']) && $now > $cache_data['expire']) {
                    $expired_count++;
                }
            }
        }

        return array(
            'total_files' => $file_count,
            'expired_files' => $expired_count,
            'total_size' => size_format($total_size, 2),
            'cache_dir' => $this->cache_dir,
            'is_writable' => wp_is_writable($this->cache_dir)
        );
    }
}