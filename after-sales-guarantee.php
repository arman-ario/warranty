<?php
/*
Plugin Name: After Sales Guarantee
Plugin URI: https://github.com/arman-ario/warranty
Description: سیستم مدیریت گارانتی و خدمات پس از فروش
Version: 1.0.0
Author: Arman Ario
Author URI: https://github.com/arman-ario
Text Domain: after-sales-guarantee
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

// تعریف ثابت‌ها
define('ASG_VERSION', '1.0.0');
define('ASG_FILE', __FILE__);
define('ASG_PATH', plugin_dir_path(__FILE__));
define('ASG_URL', plugin_dir_url(__FILE__));

// لود کردن کلاس‌ها
function asg_load_classes() {
    require_once ASG_PATH . 'includes/class-asg-db.php';
    require_once ASG_PATH . 'includes/class-asg-admin.php';
    require_once ASG_PATH . 'includes/class-asg-public.php';
}
add_action('plugins_loaded', 'asg_load_classes');

// افزودن منو
function asg_admin_menu() {
    add_menu_page(
        'مدیریت گارانتی',
        'گارانتی',
        'manage_options',
        'warranty-management',
        'asg_admin_page',
        'dashicons-shield',
        30
    );

    // زیرمنوها
    add_submenu_page(
        'warranty-management',
        'افزودن گارانتی جدید',
        'افزودن گارانتی',
        'manage_options',
        'warranty-add',
        'asg_add_warranty_page'
    );

    add_submenu_page(
        'warranty-management',
        'تنظیمات گارانتی',
        'تنظیمات',
        'manage_options',
        'warranty-settings',
        'asg_settings_page'
    );
}
add_action('admin_menu', 'asg_admin_menu');

// صفحه اصلی مدیریت
function asg_admin_page() {
    if (file_exists(ASG_PATH . 'templates/admin/main.php')) {
        include ASG_PATH . 'templates/admin/main.php';
    } else {
        echo '<div class="wrap">';
        echo '<h1>مدیریت گارانتی</h1>';
        echo '<div class="notice notice-error"><p>فایل قالب یافت نشد!</p></div>';
        echo '</div>';
    }
}

// صفحه افزودن گارانتی
function asg_add_warranty_page() {
    if (file_exists(ASG_PATH . 'templates/admin/add-warranty.php')) {
        include ASG_PATH . 'templates/admin/add-warranty.php';
    } else {
        echo '<div class="wrap">';
        echo '<h1>افزودن گارانتی جدید</h1>';
        echo '<div class="notice notice-error"><p>فایل قالب یافت نشد!</p></div>';
        echo '</div>';
    }
}

// صفحه تنظیمات
function asg_settings_page() {
    if (file_exists(ASG_PATH . 'templates/admin/settings.php')) {
        include ASG_PATH . 'templates/admin/settings.php';
    } else {
        echo '<div class="wrap">';
        echo '<h1>تنظیمات گارانتی</h1>';
        echo '<div class="notice notice-error"><p>فایل قالب یافت نشد!</p></div>';
        echo '</div>';
    }
}

// لود کردن استایل‌ها
function asg_admin_enqueue_scripts($hook) {
    // فقط در صفحات افزونه
    if (strpos($hook, 'warranty-') !== false) {
        wp_enqueue_style(
            'asg-admin-style',
            ASG_URL . 'assets/css/admin.css',
            array(),
            ASG_VERSION
        );
        
        wp_enqueue_script(
            'asg-admin-script',
            ASG_URL . 'assets/js/admin.js',
            array('jquery'),
            ASG_VERSION,
            true
        );
    }
}
add_action('admin_enqueue_scripts', 'asg_admin_enqueue_scripts');

// فعال‌سازی افزونه
register_activation_hook(__FILE__, 'asg_activate');
function asg_activate() {
    // ایجاد جداول دیتابیس
    require_once ASG_PATH . 'includes/class-asg-db.php';
    $db = new ASG_DB();
    $db->create_tables();

    flush_rewrite_rules();
}

// غیرفعال‌سازی افزونه
register_deactivation_hook(__FILE__, 'asg_deactivate');
function asg_deactivate() {
    flush_rewrite_rules();
}