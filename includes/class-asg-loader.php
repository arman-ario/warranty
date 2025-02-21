<?php
/**
 * کلاس مدیریت بارگذاری اجزای افزونه
 */
class ASG_Loader {
    /**
     * راه‌اندازی اولیه لودر
     */
    public static function init() {
        // بارگذاری کلاس‌های ادمین
        require_once plugin_dir_path(__FILE__) . 'admin/class-asg-warranty-registration.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-asg-bulk-registration.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-asg-reports.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-asg-charts.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-asg-settings.php';
        require_once plugin_dir_path(__FILE__) . 'admin/class-asg-debug.php';

        // ثبت منوهای مدیریت
        add_action('admin_menu', [__CLASS__, 'register_admin_menus']);
    }

    /**
     * ثبت تمام منوهای مدیریت
     */
    public static function register_admin_menus() {
        // منوی اصلی
        add_menu_page(
            'مدیریت گارانتی',
            'گارانتی',
            'manage_options',
            'warranty-management',
            [ASG_Warranty_Registration::class, 'render_page'],
            'dashicons-shield',
            6
        );

        // زیرمنوها
        self::register_submenus();
    }

    /**
     * ثبت زیرمنوها
     */
    private static function register_submenus() {
        $submenus = [
            [
                'title' => 'ثبت گارانتی جدید',
                'menu_title' => 'ثبت گارانتی جدید',
                'capability' => 'manage_options',
                'menu_slug' => 'warranty-management-add',
                'callback' => [ASG_Warranty_Registration::class, 'render_add_page']
            ],
            [
                'title' => 'ثبت گارانتی دسته‌ای',
                'menu_title' => 'ثبت گارانتی دسته‌ای',
                'capability' => 'manage_options',
                'menu_slug' => 'warranty-management-bulk',
                'callback' => [ASG_Bulk_Registration::class, 'render_page']
            ],
            // ... سایر زیرمنوها
        ];

        foreach ($submenus as $submenu) {
            add_submenu_page(
                'warranty-management',
                $submenu['title'],
                $submenu['menu_title'],
                $submenu['capability'],
                $submenu['menu_slug'],
                $submenu['callback']
            );
        }
    }
}