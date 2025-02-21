<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت بخش ادمین افزونه
 */
class ASG_Admin {
    private static $instance = null;
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
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_init', array($this, 'init_admin'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_filter('plugin_action_links_' . plugin_basename(ASG_PLUGIN_DIR . 'after-sales-guarantee.php'), 
                  array($this, 'add_plugin_links'));
    }

    /**
     * افزودن منوهای مدیریت
     */
    public function add_admin_menu() {
        // منوی اصلی
        add_menu_page(
            'مدیریت گارانتی',
            'گارانتی',
            'manage_options',
            'warranty-management',
            array($this, 'render_main_page'),
            'dashicons-shield',
            25
        );

        // زیرمنوها
        $submenus = array(
            array(
                'parent' => 'warranty-management',
                'title' => 'لیست درخواست‌ها',
                'menu_title' => 'لیست درخواست‌ها',
                'capability' => 'manage_options',
                'menu_slug' => 'warranty-management',
                'callback' => array($this, 'render_main_page')
            ),
            array(
                'parent' => 'warranty-management',
                'title' => 'ثبت گارانتی جدید',
                'menu_title' => 'ثبت گارانتی جدید',
                'capability' => 'manage_options',
                'menu_slug' => 'warranty-management-add',
                'callback' => array('ASG_Warranty_Registration', 'render_page')
            ),
            array(
                'parent' => 'warranty-management',
                'title' => 'ثبت دسته‌ای',
                'menu_title' => 'ثبت دسته‌ای',
                'capability' => 'manage_options',
                'menu_slug' => 'warranty-management-bulk',
                'callback' => array('ASG_Bulk_Registration', 'render_page')
            ),
            array(
                'parent' => 'warranty-management',
                'title' => 'گزارشات',
                'menu_title' => 'گزارشات',
                'capability' => 'manage_options',
                'menu_slug' => 'warranty-management-reports',
                'callback' => array('ASG_Reports', 'render_page')
            ),
            array(
                'parent' => 'warranty-management',
                'title' => 'تنظیمات',
                'menu_title' => 'تنظیمات',
                'capability' => 'manage_options',
                'menu_slug' => 'warranty-management-settings',
                'callback' => array('ASG_Settings', 'render_page')
            )
        );

        foreach ($submenus as $submenu) {
            add_submenu_page(
                $submenu['parent'],
                $submenu['title'],
                $submenu['menu_title'],
                $submenu['capability'],
                $submenu['menu_slug'],
                $submenu['callback']
            );
        }
    }

    /**
     * افزودن فایل‌های CSS و JS
     */
    public function enqueue_admin_assets($hook) {
        // اگر در صفحات افزونه نیستیم، فایل‌ها را لود نکن
        if (strpos($hook, 'warranty-management') === false) {
            return;
        }

        // فایل‌های CSS
        wp_enqueue_style('asg-admin', ASG_PLUGIN_URL . 'assets/css/asg-admin.css', array(), ASG_VERSION);
        wp_enqueue_style('persian-datepicker', ASG_PLUGIN_URL . 'assets/css/persian-datepicker.min.css', array(), ASG_VERSION);

        // فایل‌های JS
        wp_enqueue_media();
        wp_enqueue_script('persian-date', ASG_PLUGIN_URL . 'assets/js/persian-date.min.js', array('jquery'), ASG_VERSION, true);
        wp_enqueue_script('persian-datepicker', ASG_PLUGIN_URL . 'assets/js/persian-datepicker.min.js', array('persian-date'), ASG_VERSION, true);
        wp_enqueue_script('asg-admin', ASG_PLUGIN_URL . 'assets/js/asg-script.js', array('jquery'), ASG_VERSION, true);

        // اگر بهینه‌سازی فایل‌ها فعال است
        if (!empty($this->settings['optimize_assets'])) {
            wp_enqueue_style('asg-optimized', ASG_PLUGIN_URL . 'assets/css/optimized.min.css', array(), ASG_VERSION);
            wp_enqueue_script('asg-optimized', ASG_PLUGIN_URL . 'assets/js/optimized.min.js', array('jquery'), ASG_VERSION, true);
        }

        // لوکال‌های جاوااسکریپت
        wp_localize_script('asg-admin', 'asgAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('asg-admin-nonce'),
            'strings' => array(
                'confirmDelete' => 'آیا از حذف این مورد اطمینان دارید؟',
                'warrantyAdded' => 'گارانتی با موفقیت ثبت شد.',
                'warrantyUpdated' => 'گارانتی با موفقیت بروزرسانی شد.',
                'error' => 'خطایی رخ داده است. لطفا مجددا تلاش کنید.'
            )
        ));
    }

    /**
     * مقداردهی اولیه ادمین
     */
    public function init_admin() {
        // ثبت تنظیمات
        register_setting('asg_settings', 'asg_settings');
        
        // بررسی نیازمندی‌ها
        $this->check_requirements();
    }

    /**
     * نمایش نوتیفیکیشن‌های ادمین
     */
    public function admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // بررسی نیازمندی‌ها
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>';
            echo 'افزونه گارانتی نیاز به نصب و فعال بودن ووکامرس دارد.';
            echo '</p></div>';
        }

        // بررسی تنظیمات ضروری
        if (empty($this->settings['default_warranty_duration'])) {
            echo '<div class="notice notice-warning"><p>';
            echo 'لطفا مدت زمان پیش‌فرض گارانتی را در تنظیمات مشخص کنید.';
            echo '</p></div>';
        }
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
     * صفحه اصلی مدیریت
     */
    public function render_main_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        // لود قالب لیست درخواست‌ها
        require_once ASG_PLUGIN_DIR . 'templates/admin/list-guarantees.php';
    }

    /**
     * بررسی نیازمندی‌های افزونه
     */
    private function check_requirements() {
        // بررسی نسخه PHP
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo 'افزونه گارانتی نیاز به PHP نسخه 7.4 یا بالاتر دارد.';
                echo '</p></div>';
            });
        }

        // بررسی نسخه وردپرس
        if (version_compare(get_bloginfo('version'), '5.0', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo 'افزونه گارانتی نیاز به وردپرس نسخه 5.0 یا بالاتر دارد.';
                echo '</p></div>';
            });
        }

        // بررسی نصب بودن ووکامرس
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo 'افزونه گارانتی نیاز به نصب و فعال بودن ووکامرس دارد.';
                echo '</p></div>';
            });
        }
    }
}