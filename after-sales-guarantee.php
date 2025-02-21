<?php
/*
Plugin Name: After Sales Guarantee
Description: مدیریت گارانتی و خدمات پس از فروش برای ووکامرس
Version: 1.8
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

// تعریف ثابت‌های افزونه
define('ASG_VERSION', '1.8');
define('ASG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASG_PLUGIN_URL', plugin_dir_url(__FILE__));

// لود کردن کلاس‌های اصلی
require_once ASG_PLUGIN_DIR . 'includes/class-asg-loader.php';
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

// لود کردن کلاس‌های ادمین
require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-warranty-registration.php';
require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-bulk-registration.php';
require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-charts.php';
require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-debug.php';
require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-reports.php';
require_once ASG_PLUGIN_DIR . 'includes/admin/class-asg-settings.php';

/**
 * راه‌اندازی افزونه
 */
function asg_init() {
    // راه‌اندازی لودر اصلی
    ASG_Loader::init();
    
    // راه‌اندازی کلاس‌های اصلی
    ASG_Admin::instance();
    ASG_Public::instance();
    ASG_Security::instance();
    ASG_API::instance();
    ASG_Notifications::instance();
    ASG_Performance::instance();
    ASG_Cache::instance();
    ASG_Assets_Optimizer::instance();
    
    // راه‌اندازی کلاس‌های ادمین در پنل مدیریت
    if (is_admin()) {
        ASG_Debug::instance();
        ASG_Reports::instance();
        ASG_Settings::instance();
        ASG_Charts::instance();
    }
}
add_action('plugins_loaded', 'asg_init');

/**
 * فعال‌سازی افزونه
 */
register_activation_hook(__FILE__, 'asg_activate');
function asg_activate() {
    // ایجاد جداول دیتابیس
    ASG_DB::create_tables();
    
    // تنظیم مقادیر پیش‌فرض
    if (!get_option('asg_statuses')) {
        add_option('asg_statuses', array(
            'در انتظار بررسی',
            'آماده ارسال',
            'ارسال شده',
            'تعویض شده',
            'خارج از گارانتی'
        ));
    }

    // تنظیمات پیش‌فرض
    $default_settings = array(
        'default_warranty_duration' => 12,
        'default_warranty_status' => 'در انتظار بررسی',
        'enable_notifications' => 1,
        'items_per_page' => 10,
        'enable_logging' => 0,
        'enable_cache' => 1,
        'optimize_assets' => 1
    );
    
    if (!get_option('asg_settings')) {
        add_option('asg_settings', $default_settings);
    }

    // پاکسازی ریرایت‌ها
    flush_rewrite_rules();
}

/**
 * غیرفعال‌سازی افزونه
 */
register_deactivation_hook(__FILE__, 'asg_deactivate');
function asg_deactivate() {
    // پاکسازی کش
    ASG_Cache::clear_all();
    
    // پاکسازی ریرایت‌ها
    flush_rewrite_rules();
}

/**
 * حذف افزونه
 */
register_uninstall_hook(__FILE__, 'asg_uninstall');
function asg_uninstall() {
    // حذف جداول دیتابیس
    ASG_DB::drop_tables();
    
    // حذف تنظیمات
    delete_option('asg_statuses');
    delete_option('asg_settings');
    
    // حذف فایل‌های کش
    ASG_Cache::delete_cache_directory();
    
    // پاکسازی ریرایت‌ها
    flush_rewrite_rules();
}