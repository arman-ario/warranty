<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت اشکال‌زدایی افزونه گارانتی
 */
class ASG_Debug {
    private static $instance = null;
    private $log_file;
    private $debug_enabled;

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
        $this->debug_enabled = defined('WP_DEBUG') && WP_DEBUG;
        $this->log_file = WP_CONTENT_DIR . '/asg-debug.log';
        
        if ($this->debug_enabled) {
            add_action('admin_menu', array($this, 'add_debug_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_debug_assets'));
        }
    }

    /**
     * افزودن منوی اشکال‌زدایی
     */
    public function add_debug_menu() {
        add_submenu_page(
            'warranty-management',
            'اشکال‌زدایی',
            'اشکال‌زدایی',
            'manage_options',
            'warranty-management-debug',
            array($this, 'render_debug_page')
        );
    }

    /**
     * افزودن فایل‌های CSS و JS
     */
    public function enqueue_debug_assets($hook) {
        if ('warranty-management_page_warranty-management-debug' !== $hook) {
            return;
        }

        wp_add_inline_style('admin-bar', '
            .debug-section {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .debug-table {
                width: 100%;
                border-collapse: collapse;
            }
            .debug-table th, .debug-table td {
                padding: 10px;
                text-align: right;
                border-bottom: 1px solid #eee;
            }
            .status-ok { color: #46b450; }
            .status-warning { color: #ffb900; }
            .status-error { color: #dc3232; }
            .code-block {
                background: #f5f5f5;
                padding: 5px 10px;
                border-radius: 3px;
                font-family: monospace;
            }
            .refresh-button {
                margin: 20px 0;
            }
        ');
    }

    /**
     * صفحه اصلی اشکال‌زدایی
     */
    public function render_debug_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        echo '<div class="wrap">';
        echo '<h1>اشکال‌زدایی افزونه گارانتی</h1>';

        // دکمه بروزرسانی
        echo '<div class="refresh-button">';
        echo '<button class="button button-primary" onclick="window.location.reload();">بروزرسانی اطلاعات</button>';
        echo '</div>';

        // بخش اطلاعات سیستم
        $this->render_system_info();

        // بخش دیتابیس
        $this->render_database_info();

        // بخش فایل‌ها
        $this->render_files_info();

        // بخش لاگ‌ها
        $this->render_logs_section();

        echo '</div>';
    }

    /**
     * نمایش اطلاعات سیستم
     */
    private function render_system_info() {
        echo '<div class="debug-section">';
        echo '<h2>اطلاعات سیستم</h2>';
        echo '<table class="debug-table">';

        // PHP نسخه
        $php_version = phpversion();
        $php_status = version_compare($php_version, '7.4', '>=') ? 'ok' : 'error';
        echo $this->render_status_row(
            'نسخه PHP',
            $php_version,
            $php_status,
            $php_status === 'error' ? 'نیاز به PHP 7.4 یا بالاتر' : ''
        );

        // وردپرس نسخه
        echo $this->render_status_row(
            'نسخه وردپرس',
            get_bloginfo('version'),
            'ok'
        );

        // حافظه PHP
        $memory_limit = ini_get('memory_limit');
        $memory_status = (int)$memory_limit >= 128 ? 'ok' : 'warning';
        echo $this->render_status_row(
            'محدودیت حافظه PHP',
            $memory_limit,
            $memory_status,
            $memory_status === 'warning' ? 'پیشنهاد می‌شود حداقل 128M باشد' : ''
        );

        // زمان اجرا
        $max_execution = ini_get('max_execution_time');
        $execution_status = $max_execution >= 30 ? 'ok' : 'warning';
        echo $this->render_status_row(
            'حداکثر زمان اجرا',
            $max_execution . ' ثانیه',
            $execution_status,
            $execution_status === 'warning' ? 'پیشنهاد می‌شود حداقل 30 ثانیه باشد' : ''
        );

        echo '</table>';
        echo '</div>';
    }

    /**
     * نمایش اطلاعات دیتابیس
     */
    private function render_database_info() {
        global $wpdb;
        
        echo '<div class="debug-section">';
        echo '<h2>وضعیت دیتابیس</h2>';
        echo '<table class="debug-table">';

        $tables = array(
            'asg_guarantee_requests' => 'درخواست‌های گارانتی',
            'asg_guarantee_notes' => 'یادداشت‌های گارانتی',
            'asg_notifications' => 'نوتیفیکیشن‌ها'
        );

        foreach ($tables as $table => $label) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
            
            if ($exists) {
                $count = $wpdb->get_var("SELECT COUNT(*) FROM $full_table");
                echo $this->render_status_row(
                    $label,
                    "موجود ($count رکورد)",
                    'ok'
                );
            } else {
                echo $this->render_status_row(
                    $label,
                    'جدول وجود ندارد',
                    'error'
                );
            }
        }

        echo '</table>';
        echo '</div>';
    }

    /**
     * نمایش اطلاعات فایل‌ها
     */
    private function render_files_info() {
        echo '<div class="debug-section">';
        echo '<h2>بررسی فایل‌ها</h2>';
        echo '<table class="debug-table">';

        $files = array(
            'class-asg-db.php' => 'کلاس دیتابیس',
            'class-asg-api.php' => 'کلاس API',
            'class-asg-reports.php' => 'کلاس گزارشات',
            'class-asg-charts.php' => 'کلاس نمودارها',
            'class-asg-settings.php' => 'کلاس تنظیمات'
        );

        foreach ($files as $file => $label) {
            $path = ASG_PLUGIN_DIR . 'includes/admin/' . $file;
            $exists = file_exists($path);
            $readable = is_readable($path);
            
            echo $this->render_status_row(
                $label,
                $exists ? ($readable ? 'موجود و قابل خواندن' : 'موجود اما غیرقابل خواندن') : 'یافت نشد',
                $exists ? ($readable ? 'ok' : 'warning') : 'error'
            );
        }

        echo '</table>';
        echo '</div>';
    }

    /**
     * نمایش بخش لاگ‌ها
     */
    private function render_logs_section() {
        echo '<div class="debug-section">';
        echo '<h2>لاگ‌های سیستم</h2>';

        if (file_exists($this->log_file)) {
            $logs = $this->get_recent_logs();
            if (!empty($logs)) {
                echo '<div class="log-content" style="max-height: 400px; overflow-y: auto; background: #f5f5f5; padding: 10px;">';
                foreach ($logs as $log) {
                    echo '<div class="log-entry">' . esc_html($log) . '</div>';
                }
                echo '</div>';
            } else {
                echo '<p>هیچ لاگی ثبت نشده است.</p>';
            }
        } else {
            echo '<p>فایل لاگ وجود ندارد.</p>';
        }

        echo '</div>';
    }

    /**
     * ثبت لاگ جدید
     */
    public function log($message, $type = 'info') {
        if (!$this->debug_enabled) {
            return false;
        }

        $timestamp = current_time('Y-m-d H:i:s');
        $log_message = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($type), $message);
        
        return error_log($log_message, 3, $this->log_file);
    }

    /**
     * دریافت لاگ‌های اخیر
     */
    private function get_recent_logs($lines = 100) {
        if (!file_exists($this->log_file)) {
            return array();
        }

        $logs = array();
        $file = new SplFileObject($this->log_file, 'r');
        $file->seek(PHP_INT_MAX);
        $last_line = $file->key();

        $start = max(0, $last_line - $lines);
        
        for ($i = $start; $i <= $last_line; $i++) {
            $file->seek($i);
            $line = $file->current();
            if (trim($line) !== '') {
                $logs[] = $line;
            }
        }

        return $logs;
    }

    /**
     * ایجاد یک ردیف در جدول وضعیت
     */
    private function render_status_row($label, $value, $status, $message = '') {
        $status_class = 'status-' . $status;
        $status_icon = $status === 'ok' ? '✓' : ($status === 'warning' ? '⚠' : '✗');
        
        $html = "<tr>";
        $html .= "<td>$label</td>";
        $html .= "<td><span class='$status_class'>$status_icon $value</span>";
        if ($message) {
            $html .= " <span class='description'>($message)</span>";
        }
        $html .= "</td>";
        $html .= "</tr>";
        
        return $html;
    }

    /**
     * پاکسازی لاگ‌ها
     */
    public function clear_logs() {
        if (file_exists($this->log_file) && is_writable($this->log_file)) {
            return unlink($this->log_file);
        }
        return false;
    }
}