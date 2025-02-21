<?php
/**
 * Plugin Name: After Sales Guarantee
 * Plugin URI: https://github.com/arman-ario/warranty
 * Description: سیستم مدیریت گارانتی و خدمات پس از فروش
 * Version: 1.0.0
 * Author: Arman Ario
 * Author URI: https://github.com/arman-ario
 * Text Domain: after-sales-guarantee
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    die('دسترسی مستقیم غیرمجاز است!');
}

// تعریف ثابت‌ها
if (!defined('ASG_VERSION')) {
    define('ASG_VERSION', '1.0.0');
}
if (!defined('ASG_PLUGIN_DIR')) {
    define('ASG_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('ASG_PLUGIN_URL')) {
    define('ASG_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('ASG_PLUGIN_BASENAME')) {
    define('ASG_PLUGIN_BASENAME', plugin_basename(__FILE__));
}

// بررسی وجود WooCommerce
function asg_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>';
            echo 'افزونه گارانتی نیاز به نصب و فعال‌سازی WooCommerce دارد.';
            echo '</p></div>';
        });
        return false;
    }
    return true;
}

// فعال‌سازی خطاها در حالت دیباگ
if (defined('WP_DEBUG') && WP_DEBUG === true) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// لود کردن فایل‌های اصلی
function asg_load_files() {
    $files = array(
        'includes/class-asg-loader.php',
        'includes/class-asg-db.php',
        'includes/class-asg-public.php',
        'includes/class-asg-admin.php',
        'includes/class-asg-security.php',
    );

    foreach ($files as $file) {
        $path = ASG_PLUGIN_DIR . $file;
        if (file_exists($path)) {
            require_once $path;
        } else {
            error_log("ASG Error: File not found - $path");
            return false;
        }
    }
    return true;
}

// فعال‌سازی افزونه
function asg_activate() {
    if (!asg_check_woocommerce()) {
        return;
    }

    if (!class_exists('ASG_DB')) {
        require_once ASG_PLUGIN_DIR . 'includes/class-asg-db.php';
    }

    try {
        // ایجاد جداول دیتابیس
        ASG_DB::create_tables();
        
        // افزودن تنظیمات پیش‌فرض
        if (!get_option('asg_settings')) {
            update_option('asg_settings', array(
                'enable_cache' => true,
                'default_warranty_duration' => 12,
                'enable_notifications' => true,
                'items_per_page' => 20,
                'log_retention_days' => 30
            ));
        }
        
        // ایجاد دایرکتوری‌های مورد نیاز
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/asg-cache';
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }

        flush_rewrite_rules();
        
    } catch (Exception $e) {
        error_log("ASG Activation Error: " . $e->getMessage());
        wp_die('خطا در فعال‌سازی افزونه. لطفا لاگ را بررسی کنید.');
    }
}

// غیرفعال‌سازی افزونه
function asg_deactivate() {
    flush_rewrite_rules();
}

// حذف افزونه
function asg_uninstall() {
    // پاک کردن تنظیمات و داده‌ها
    delete_option('asg_settings');
    
    // پاک کردن دایرکتوری کش
    $upload_dir = wp_upload_dir();
    $cache_dir = $upload_dir['basedir'] . '/asg-cache';
    if (is_dir($cache_dir)) {
        array_map('unlink', glob("$cache_dir/*.*"));
        rmdir($cache_dir);
    }
}

// راه‌اندازی افزونه
function asg_init() {
    if (!asg_check_woocommerce()) {
        return;
    }

    if (!asg_load_files()) {
        return;
    }

    try {
        // راه‌اندازی کلاس‌های اصلی
        ASG_Loader::instance();
        
    } catch (Exception $e) {
        error_log("ASG Init Error: " . $e->getMessage());
    }
}

// ثبت هوک‌ها
register_activation_hook(__FILE__, 'asg_activate');
register_deactivation_hook(__FILE__, 'asg_deactivate');
register_uninstall_hook(__FILE__, 'asg_uninstall');
add_action('plugins_loaded', 'asg_init');
