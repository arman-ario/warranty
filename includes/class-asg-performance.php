<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت عملکرد و بهینه‌سازی افزونه
 */
class ASG_Performance {
    private static $instance = null;
    private $settings;
    private $cache;
    private $query_cache = array();
    private $debug_mode = false;
    private $performance_logs = array();

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
        $this->cache = ASG_Cache::instance();
        $this->debug_mode = defined('WP_DEBUG') && WP_DEBUG;

        $this->init_hooks();
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function init_hooks() {
        // بهینه‌سازی کوئری‌ها
        add_action('pre_get_posts', array($this, 'optimize_queries'));
        
        // کش کردن نتایج کوئری‌ها
        add_filter('posts_request', array($this, 'maybe_cache_query'), 10, 2);
        
        // مانیتورینگ عملکرد
        if ($this->debug_mode) {
            add_action('all', array($this, 'log_hook_performance'), 1);
            add_action('all', array($this, 'log_hook_performance_end'), 999);
        }
    }

    /**
     * بهینه‌سازی کوئری‌ها
     */
    public function optimize_queries($query) {
        if (!is_admin() && $query->is_main_query()) {
            // تنظیم تعداد آیتم‌ها در هر صفحه
            if (!empty($this->settings['items_per_page'])) {
                $query->set('posts_per_page', intval($this->settings['items_per_page']));
            }

            // بهینه‌سازی کوئری‌های مربوط به گارانتی
            if (isset($query->query_vars['post_type']) && $query->query_vars['post_type'] === 'warranty') {
                $query->set('no_found_rows', true);
                $query->set('update_post_term_cache', false);
                $query->set('update_post_meta_cache', false);
            }
        }

        return $query;
    }

    /**
     * کش کردن نتایج کوئری
     */
    public function maybe_cache_query($request, $query) {
        if (empty($this->settings['enable_cache'])) {
            return $request;
        }

        // فقط کوئری‌های خواندن را کش کن
        if (stripos($request, 'SELECT') !== 0) {
            return $request;
        }

        $cache_key = 'query_' . md5($request);

        // بررسی کش
        $cached_result = $this->cache->get($cache_key, 'queries');
        if ($cached_result !== false) {
            $this->query_cache[$request] = $cached_result;
            return false; // جلوگیری از اجرای کوئری
        }

        return $request;
    }

    /**
     * ثبت زمان شروع اجرای هوک
     */
    public function log_hook_performance($hook) {
        if (!isset($this->performance_logs[$hook])) {
            $this->performance_logs[$hook] = array(
                'start_time' => microtime(true),
                'memory_start' => memory_get_usage(),
                'count' => 1
            );
        } else {
            $this->performance_logs[$hook]['count']++;
        }
    }

    /**
     * ثبت زمان پایان اجرای هوک
     */
    public function log_hook_performance_end($hook) {
        if (isset($this->performance_logs[$hook])) {
            $this->performance_logs[$hook]['end_time'] = microtime(true);
            $this->performance_logs[$hook]['memory_end'] = memory_get_usage();
            $this->performance_logs[$hook]['duration'] = 
                $this->performance_logs[$hook]['end_time'] - 
                $this->performance_logs[$hook]['start_time'];
            $this->performance_logs[$hook]['memory_usage'] = 
                $this->performance_logs[$hook]['memory_end'] - 
                $this->performance_logs[$hook]['memory_start'];
        }
    }

    /**
     * بهینه‌سازی کوئری‌های دیتابیس
     */
    public function optimize_db_queries($query) {
        global $wpdb;

        // اضافه کردن ایندکس‌ها به جداول
        $this->maybe_add_indexes();

        // بهینه‌سازی جوین‌ها
        if (stripos($query, 'JOIN') !== false) {
            $query = $this->optimize_joins($query);
        }

        return $query;
    }

    /**
     * افزودن ایندکس‌های مورد نیاز
     */
    private function maybe_add_indexes() {
        global $wpdb;

        $table = $wpdb->prefix . 'asg_guarantee_requests';
        $indexes = $wpdb->get_results("SHOW INDEX FROM $table");
        $existing_indexes = array();

        foreach ($indexes as $index) {
            $existing_indexes[] = $index->Column_name;
        }

        // افزودن ایندکس‌های ضروری
        $required_indexes = array(
            'product_id',
            'user_id',
            'status',
            'created_at',
            'expiry_date'
        );

        foreach ($required_indexes as $column) {
            if (!in_array($column, $existing_indexes)) {
                $wpdb->query("ALTER TABLE $table ADD INDEX ($column)");
            }
        }
    }

    /**
     * بهینه‌سازی جوین‌ها
     */
    private function optimize_joins($query) {
        // استفاده از INNER JOIN به جای LEFT JOIN در صورت امکان
        $query = str_replace('LEFT JOIN', 'INNER JOIN', $query);

        // اضافه کردن شرط‌های WHERE قبل از JOIN
        if (stripos($query, 'WHERE') > stripos($query, 'JOIN')) {
            $parts = explode('WHERE', $query);
            if (count($parts) === 2) {
                $conditions = trim($parts[1]);
                $join_pos = stripos($parts[0], 'JOIN');
                $query = substr($parts[0], 0, $join_pos) . 
                         'WHERE ' . $conditions . ' ' . 
                         substr($parts[0], $join_pos);
            }
        }

        return $query;
    }

    /**
     * دریافت گزارش عملکرد
     */
    public function get_performance_report() {
        if (!$this->debug_mode) {
            return array();
        }

        $report = array(
            'hooks' => array(),
            'queries' => array(),
            'memory' => array(
                'peak' => memory_get_peak_usage(true),
                'current' => memory_get_usage(true)
            ),
            'cache' => array(
                'hits' => 0,
                'misses' => 0,
                'size' => 0
            )
        );

        // آمار هوک‌ها
        foreach ($this->performance_logs as $hook => $data) {
            if (isset($data['duration'])) {
                $report['hooks'][] = array(
                    'hook' => $hook,
                    'count' => $data['count'],
                    'duration' => round($data['duration'] * 1000, 2), // تبدیل به میلی‌ثانیه
                    'memory' => size_format($data['memory_usage'])
                );
            }
        }

        // آمار کش
        if ($this->cache) {
            $cache_stats = $this->cache->get_stats();
            $report['cache'] = array_merge($report['cache'], $cache_stats);
        }

        return $report;
    }

    /**
     * بهینه‌سازی لودینگ فایل‌ها
     */
    public function optimize_assets_loading($handle, $src) {
        // حذف نسخه از URL فایل‌ها
        if (strpos($src, 'ver=') !== false) {
            $src = remove_query_arg('ver', $src);
        }

        // اضافه کردن async/defer به اسکریپت‌ها
        if (strpos($handle, 'asg-') === 0) {
            add_filter('script_loader_tag', function($tag, $handle) {
                return str_replace(' src', ' async defer src', $tag);
            }, 10, 2);
        }

        return $src;
    }

    /**
     * پاکسازی دیتابیس
     */
    public function cleanup_database() {
        global $wpdb;

        // پاکسازی لاگ‌های قدیمی
        $days = !empty($this->settings['log_retention_days']) ? 
                intval($this->settings['log_retention_days']) : 30;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}asg_logs 
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );

        // بهینه‌سازی جداول
        $tables = array(
            $wpdb->prefix . 'asg_guarantee_requests',
            $wpdb->prefix . 'asg_logs',
            $wpdb->prefix . 'asg_meta'
        );

        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE $table");
        }
    }
}