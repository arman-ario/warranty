<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت بارگذاری افزونه
 */
class ASG_Loader {
    private static $instance = null;
    private $actions = array();
    private $filters = array();
    private $shortcodes = array();
    private $admin_pages = array();

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
        $this->init_hooks();
        $this->load_dependencies();
        $this->register_shortcodes();
    }

    /**
     * راه‌اندازی هوک‌های اصلی
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * بارگذاری وابستگی‌ها
     */
    private function load_dependencies() {
        // کلاس‌های اصلی
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-db.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-admin.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-public.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-api.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-security.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-notifications.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-performance.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-cache.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-assets-optimizer.php';
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-reports.php';

        // کلاس‌های ادمین
        if (is_admin()) {
            require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-warranty-registration.php';
            require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-bulk-registration.php';
            require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-charts.php';
            require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-debug.php';
            require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-settings.php';
        }
    }

    /**
     * راه‌اندازی اولیه افزونه
     */
    public function init() {
        // ثبت Post Types
        $this->register_post_types();
        
        // ثبت Taxonomies
        $this->register_taxonomies();
        
        // تنظیم کرون جاب‌ها
        $this->setup_cron_jobs();
    }

    /**
     * راه‌اندازی بخش ادمین
     */
    public function admin_init() {
        // ثبت تنظیمات
        register_setting('asg_settings', 'asg_settings');
        
        // افزودن لینک تنظیمات به صفحه افزونه‌ها
        add_filter('plugin_action_links_' . plugin_basename(ASG_PLUGIN_DIR . 'after-sales-guarantee.php'), 
                  array($this, 'add_plugin_links'));
    }

    /**
     * بارگذاری ترجمه
     */
    public function load_textdomain() {
        load_plugin_textdomain('after-sales-guarantee', false, ASG_PLUGIN_DIR . 'languages/');
    }

    /**
     * افزودن اکشن
     */
    public function add_action($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->actions[] = array(
            'hook' => $hook,
            'component' => $component,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        );
    }

    /**
     * افزودن فیلتر
     */
    public function add_filter($hook, $component, $callback, $priority = 10, $accepted_args = 1) {
        $this->filters[] = array(
            'hook' => $hook,
            'component' => $component,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $accepted_args
        );
    }

    /**
     * ثبت شورت‌کدها
     */
    private function register_shortcodes() {
        $this->shortcodes = array(
            'warranty_form' => array($this, 'warranty_form_shortcode'),
            'warranty_status' => array($this, 'warranty_status_shortcode'),
            'warranty_list' => array($this, 'warranty_list_shortcode')
        );

        foreach ($this->shortcodes as $tag => $callback) {
            add_shortcode($tag, $callback);
        }
    }

    /**
     * شورت‌کد فرم گارانتی
     */
    public function warranty_form_shortcode($atts) {
        ob_start();
        require ASG_PLUGIN_DIR . 'templates/public/warranty-form.php';
        return ob_get_clean();
    }

    /**
     * شورت‌کد وضعیت گارانتی
     */
    public function warranty_status_shortcode($atts) {
        ob_start();
        require ASG_PLUGIN_DIR . 'templates/public/warranty-status.php';
        return ob_get_clean();
    }

    /**
     * شورت‌کد لیست گارانتی‌ها
     */
    public function warranty_list_shortcode($atts) {
        ob_start();
        require ASG_PLUGIN_DIR . 'templates/public/warranty-list.php';
        return ob_get_clean();
    }

    /**
     * افزودن فایل‌های CSS و JS عمومی
     */
    public function enqueue_public_assets() {
        wp_enqueue_style('asg-public', ASG_PLUGIN_URL . 'assets/css/asg-public.css', array(), ASG_VERSION);
        wp_enqueue_style('persian-datepicker', ASG_PLUGIN_URL . 'assets/css/persian-datepicker.min.css', array(), ASG_VERSION);

        wp_enqueue_script('persian-date', ASG_PLUGIN_URL . 'assets/js/persian-date.min.js', array('jquery'), ASG_VERSION, true);
        wp_enqueue_script('persian-datepicker', ASG_PLUGIN_URL . 'assets/js/persian-datepicker.min.js', array('persian-date'), ASG_VERSION, true);
        wp_enqueue_script('asg-public', ASG_PLUGIN_URL . 'assets/js/asg-script.js', array('jquery'), ASG_VERSION, true);

        wp_localize_script('asg-public', 'asgPublic', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('asg-public-nonce')
        ));
    }

    /**
     * تنظیم کرون جاب‌ها
     */
    private function setup_cron_jobs() {
        if (!wp_next_scheduled('asg_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'asg_daily_cleanup');
        }

        if (!wp_next_scheduled('asg_warranty_expiry_check')) {
            wp_schedule_event(time(), 'daily', 'asg_warranty_expiry_check');
        }
    }

    /**
     * ثبت Post Types
     */
    private function register_post_types() {
        // اگر نیاز به ثبت post type دارید، اینجا اضافه کنید
    }

    /**
     * ثبت Taxonomies
     */
    private function register_taxonomies() {
        // اگر نیاز به ثبت taxonomy دارید، اینجا اضافه کنید
    }

    /**
     * افزودن لینک‌های پلاگین
     */
    public function add_plugin_links($links) {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=warranty-management-settings') . '">تنظیمات</a>',
            '<a href="' . admin_url('admin.php?page=warranty-management-reports') . '">گزارشات</a>'
        );
        return array_merge($plugin_links, $links);
    }

    /**
     * راه‌اندازی تمام هوک‌ها
     */
    public function run() {
        // ثبت اکشن‌ها
        foreach ($this->actions as $hook) {
            add_action(
                $hook['hook'],
                array($hook['component'], $hook['callback']),
                $hook['priority'],
                $hook['accepted_args']
            );
        }

        // ثبت فیلترها
        foreach ($this->filters as $hook) {
            add_filter(
                $hook['hook'],
                array($hook['component'], $hook['callback']),
                $hook['priority'],
                $hook['accepted_args']
            );
        }
    }
}