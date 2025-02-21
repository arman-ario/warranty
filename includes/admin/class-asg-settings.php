<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت تنظیمات افزونه گارانتی
 */
class ASG_Settings {
    private static $instance = null;
    private $options;
    private $option_name = 'asg_settings';

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
        $this->options = get_option($this->option_name, array());
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * افزودن فایل‌های CSS و JS
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'warranty-management-settings') === false) {
            return;
        }

        wp_add_inline_style('admin-bar', '
            .asg-settings-card {
                background: white;
                padding: 20px;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            .asg-settings-section {
                margin-bottom: 30px;
            }
            .asg-settings-field {
                margin-bottom: 15px;
            }
            .asg-settings-field label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            .asg-settings-field input[type="text"],
            .asg-settings-field input[type="number"],
            .asg-settings-field input[type="email"],
            .asg-settings-field select,
            .asg-settings-field textarea {
                width: 100%;
                max-width: 400px;
            }
            .asg-settings-description {
                color: #666;
                font-style: italic;
                margin-top: 5px;
            }
        ');
    }

    /**
     * ثبت تنظیمات در وردپرس
     */
    public function register_settings() {
        register_setting(
            $this->option_name,
            $this->option_name,
            array($this, 'sanitize_settings')
        );

        // بخش تنظیمات عمومی
        add_settings_section(
            'asg_general_settings',
            'تنظیمات عمومی',
            array($this, 'render_general_section'),
            'asg-settings'
        );

        // مدت زمان گارانتی پیش‌فرض
        add_settings_field(
            'default_warranty_duration',
            'مدت زمان گارانتی پیش‌فرض',
            array($this, 'render_warranty_duration_field'),
            'asg-settings',
            'asg_general_settings'
        );

        // وضعیت پیش‌فرض گارانتی
        add_settings_field(
            'default_warranty_status',
            'وضعیت پیش‌فرض گارانتی',
            array($this, 'render_warranty_status_field'),
            'asg-settings',
            'asg_general_settings'
        );

        // بخش تنظیمات اعلان‌ها
        add_settings_section(
            'asg_notification_settings',
            'تنظیمات اعلان‌ها',
            array($this, 'render_notification_section'),
            'asg-settings'
        );

        // فعال/غیرفعال کردن اعلان‌ها
        add_settings_field(
            'enable_notifications',
            'اعلان‌ها',
            array($this, 'render_notifications_field'),
            'asg-settings',
            'asg_notification_settings'
        );

        // ایمیل مدیر
        add_settings_field(
            'admin_email',
            'ایمیل مدیر',
            array($this, 'render_admin_email_field'),
            'asg-settings',
            'asg_notification_settings'
        );

        // بخش تنظیمات پیشرفته
        add_settings_section(
            'asg_advanced_settings',
            'تنظیمات پیشرفته',
            array($this, 'render_advanced_section'),
            'asg-settings'
        );

        // تعداد آیتم در هر صفحه
        add_settings_field(
            'items_per_page',
            'تعداد آیتم در هر صفحه',
            array($this, 'render_items_per_page_field'),
            'asg-settings',
            'asg_advanced_settings'
        );

        // فعال/غیرفعال کردن لاگ
        add_settings_field(
            'enable_logging',
            'سیستم لاگ',
            array($this, 'render_logging_field'),
            'asg-settings',
            'asg_advanced_settings'
        );
    }

    /**
     * نمایش صفحه تنظیمات
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        echo '<div class="wrap">';
        echo '<h1>تنظیمات افزونه گارانتی</h1>';

        if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
            echo '<div class="notice notice-success"><p>تنظیمات با موفقیت ذخیره شد.</p></div>';
        }

        echo '<form method="post" action="options.php" class="asg-settings-form">';
        
        settings_fields($this->option_name);
        
        echo '<div class="asg-settings-card">';
        do_settings_sections('asg-settings');
        echo '</div>';
        
        submit_button('ذخیره تنظیمات');
        
        echo '</form>';
        echo '</div>';
    }

    /**
     * توضیحات بخش تنظیمات عمومی
     */
    public function render_general_section() {
        echo '<p>تنظیمات اصلی سیستم گارانتی را در این بخش انجام دهید.</p>';
    }

    /**
     * توضیحات بخش تنظیمات اعلان‌ها
     */
    public function render_notification_section() {
        echo '<p>تنظیمات مربوط به اعلان‌ها و اطلاع‌رسانی‌ها را در این بخش انجام دهید.</p>';
    }

    /**
     * توضیحات بخش تنظیمات پیشرفته
     */
    public function render_advanced_section() {
        echo '<p>این تنظیمات برای کاربران پیشرفته است. لطفاً با احتیاط تغییر دهید.</p>';
    }

    /**
     * فیلد مدت زمان گارانتی
     */
    public function render_warranty_duration_field() {
        $duration = isset($this->options['default_warranty_duration']) ? 
                   $this->options['default_warranty_duration'] : 12;
        
        echo '<input type="number" 
                     name="' . $this->option_name . '[default_warranty_duration]" 
                     value="' . esc_attr($duration) . '" 
                     min="1" 
                     max="120"
              /> ماه';
        echo '<p class="asg-settings-description">مدت زمان پیش‌فرض برای گارانتی محصولات جدید</p>';
    }

    /**
     * فیلد وضعیت پیش‌فرض
     */
    public function render_warranty_status_field() {
        $status = isset($this->options['default_warranty_status']) ? 
                 $this->options['default_warranty_status'] : 'در انتظار بررسی';
        
        $statuses = get_option('asg_statuses', array(
            'در انتظار بررسی',
            'آماده ارسال',
            'ارسال شده',
            'تعویض شده',
            'خارج از گارانتی'
        ));

        echo '<select name="' . $this->option_name . '[default_warranty_status]">';
        foreach ($statuses as $value) {
            $selected = ($value === $status) ? 'selected' : '';
            echo '<option value="' . esc_attr($value) . '" ' . $selected . '>' . 
                 esc_html($value) . '</option>';
        }
        echo '</select>';
        echo '<p class="asg-settings-description">وضعیت پیش‌فرض برای درخواست‌های جدید گارانتی</p>';
    }

    /**
     * فیلد اعلان‌ها
     */
    public function render_notifications_field() {
        $enabled = isset($this->options['enable_notifications']) ? 
                  $this->options['enable_notifications'] : 1;
        
        echo '<label>';
        echo '<input type="checkbox" 
                     name="' . $this->option_name . '[enable_notifications]" 
                     value="1" 
                     ' . checked($enabled, 1, false) . '
              />';
        echo ' فعال‌سازی سیستم اعلان‌ها';
        echo '</label>';
        echo '<p class="asg-settings-description">ارسال ایمیل برای تغییرات وضعیت و یادآوری‌ها</p>';
    }

    /**
     * فیلد ایمیل مدیر
     */
    public function render_admin_email_field() {
        $email = isset($this->options['admin_email']) ? 
                $this->options['admin_email'] : get_option('admin_email');
        
        echo '<input type="email" 
                     name="' . $this->option_name . '[admin_email]" 
                     value="' . esc_attr($email) . '" 
                     class="regular-text"
              />';
        echo '<p class="asg-settings-description">ایمیلی که اعلان‌های مدیریتی به آن ارسال می‌شود</p>';
    }

    /**
     * فیلد تعداد آیتم در صفحه
     */
    public function render_items_per_page_field() {
        $per_page = isset($this->options['items_per_page']) ? 
                   $this->options['items_per_page'] : 10;
        
        echo '<input type="number" 
                     name="' . $this->option_name . '[items_per_page]" 
                     value="' . esc_attr($per_page) . '" 
                     min="5" 
                     max="100"
              />';
        echo '<p class="asg-settings-description">تعداد درخواست‌های نمایش داده شده در هر صفحه</p>';
    }

    /**
     * فیلد سیستم لاگ
     */
    public function render_logging_field() {
        $enabled = isset($this->options['enable_logging']) ? 
                  $this->options['enable_logging'] : 0;
        
        echo '<label>';
        echo '<input type="checkbox" 
                     name="' . $this->option_name . '[enable_logging]" 
                     value="1" 
                     ' . checked($enabled, 1, false) . '
              />';
        echo ' فعال‌سازی سیستم لاگ';
        echo '</label>';
        echo '<p class="asg-settings-description">ثبت رویدادها برای اشکال‌زدایی (مناسب برای توسعه‌دهندگان)</p>';
    }

    /**
     * اعتبارسنجی تنظیمات
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        // مدت زمان گارانتی
        $sanitized['default_warranty_duration'] = absint($input['default_warranty_duration']);
        if ($sanitized['default_warranty_duration'] < 1) {
            $sanitized['default_warranty_duration'] = 12;
        }
        
        // وضعیت پیش‌فرض
        $sanitized['default_warranty_status'] = sanitize_text_field($input['default_warranty_status']);
        
        // اعلان‌ها
        $sanitized['enable_notifications'] = isset($input['enable_notifications']) ? 1 : 0;
        
        // ایمیل مدیر
        $sanitized['admin_email'] = sanitize_email($input['admin_email']);
        
        // تعداد آیتم در صفحه
        $sanitized['items_per_page'] = absint($input['items_per_page']);
        if ($sanitized['items_per_page'] < 5) {
            $sanitized['items_per_page'] = 10;
        }
        
        // سیستم لاگ
        $sanitized['enable_logging'] = isset($input['enable_logging']) ? 1 : 0;
        
        return $sanitized;
    }

    /**
     * دریافت یک تنظیم خاص
     */
    public function get_option($key, $default = null) {
        return isset($this->options[$key]) ? $this->options[$key] : $default;
    }

    /**
     * بروزرسانی یک تنظیم خاص
     */
    public function update_option($key, $value) {
        $this->options[$key] = $value;
        return update_option($this->option_name, $this->options);
    }
}