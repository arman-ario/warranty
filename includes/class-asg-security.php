<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت امنیت افزونه
 */
class ASG_Security {
    private static $instance = null;
    private $settings;
    private $db;
    private $failed_attempts = array();
    private $blocked_ips = array();

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
        global $wpdb;
        $this->db = $wpdb;
        $this->settings = get_option('asg_settings', array());
        
        $this->init_hooks();
        $this->load_blocked_ips();
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function init_hooks() {
        // فیلترهای امنیتی
        add_filter('asg_validate_serial_number', array($this, 'validate_serial_number'), 10, 1);
        add_filter('asg_before_warranty_save', array($this, 'sanitize_warranty_data'), 10, 1);
        
        // اکشن‌های امنیتی
        add_action('init', array($this, 'check_ip_block'));
        add_action('wp_login_failed', array($this, 'log_failed_login'));
        add_action('wp_login', array($this, 'clear_failed_attempts'), 10, 2);
        
        // محدودیت درخواست‌ها
        add_action('init', array($this, 'rate_limit_requests'));
    }

    /**
     * بارگذاری لیست IP های مسدود شده
     */
    private function load_blocked_ips() {
        $this->blocked_ips = get_option('asg_blocked_ips', array());
    }

    /**
     * اعتبارسنجی شماره سریال
     */
    public function validate_serial_number($serial) {
        // پاکسازی شماره سریال
        $serial = sanitize_text_field($serial);
        
        // بررسی فرمت
        if (!preg_match('/^[A-Z0-9]{8,16}$/', $serial)) {
            return new WP_Error('invalid_serial', 'فرمت شماره سریال نامعتبر است.');
        }

        // بررسی تکراری نبودن
        $exists = $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}asg_guarantee_requests 
                 WHERE serial_number = %s",
                $serial
            )
        );

        if ($exists) {
            return new WP_Error('duplicate_serial', 'این شماره سریال قبلاً ثبت شده است.');
        }

        return $serial;
    }

    /**
     * پاکسازی داده‌های گارانتی
     */
    public function sanitize_warranty_data($data) {
        $clean_data = array();
        
        // پاکسازی فیلدها
        if (isset($data['product_id'])) {
            $clean_data['product_id'] = absint($data['product_id']);
        }
        
        if (isset($data['user_id'])) {
            $clean_data['user_id'] = absint($data['user_id']);
        }
        
        if (isset($data['serial_number'])) {
            $clean_data['serial_number'] = sanitize_text_field($data['serial_number']);
        }
        
        if (isset($data['purchase_date'])) {
            $clean_data['purchase_date'] = sanitize_text_field($data['purchase_date']);
        }
        
        if (isset($data['status'])) {
            $clean_data['status'] = sanitize_text_field($data['status']);
        }
        
        if (isset($data['notes'])) {
            $clean_data['notes'] = wp_kses_post($data['notes']);
        }

        return $clean_data;
    }

    /**
     * بررسی مسدود بودن IP
     */
    public function check_ip_block() {
        $ip = $this->get_client_ip();
        
        if (in_array($ip, $this->blocked_ips)) {
            wp_die('دسترسی شما به دلیل فعالیت مشکوک مسدود شده است.');
        }
    }

    /**
     * ثبت تلاش‌های ناموفق ورود
     */
    public function log_failed_login($username) {
        $ip = $this->get_client_ip();
        
        if (!isset($this->failed_attempts[$ip])) {
            $this->failed_attempts[$ip] = array(
                'count' => 1,
                'first_attempt' => time()
            );
        } else {
            $this->failed_attempts[$ip]['count']++;
        }

        // بررسی تعداد تلاش‌های ناموفق
        if ($this->failed_attempts[$ip]['count'] >= 5) {
            $this->block_ip($ip);
        }

        update_option('asg_failed_login_attempts', $this->failed_attempts);
    }

    /**
     * پاک کردن تلاش‌های ناموفق بعد از ورود موفق
     */
    public function clear_failed_attempts($user_login, $user) {
        $ip = $this->get_client_ip();
        
        if (isset($this->failed_attempts[$ip])) {
            unset($this->failed_attempts[$ip]);
            update_option('asg_failed_login_attempts', $this->failed_attempts);
        }
    }

    /**
     * محدودیت تعداد درخواست‌ها
     */
    public function rate_limit_requests() {
        if (!$this->is_warranty_endpoint()) {
            return;
        }

        $ip = $this->get_client_ip();
        $rate_key = 'asg_rate_limit_' . $ip;
        $current_time = time();
        
        $requests = get_transient($rate_key);
        
        if ($requests === false) {
            set_transient($rate_key, array(
                'count' => 1,
                'timestamp' => $current_time
            ), 3600);
        } else {
            if ($current_time - $requests['timestamp'] < 60 && $requests['count'] > 10) {
                wp_die('تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً کمی صبر کنید.');
            }
            
            $requests['count']++;
            set_transient($rate_key, $requests, 3600);
        }
    }

    /**
     * مسدود کردن IP
     */
    private function block_ip($ip) {
        if (!in_array($ip, $this->blocked_ips)) {
            $this->blocked_ips[] = $ip;
            update_option('asg_blocked_ips', $this->blocked_ips);
            
            // ثبت در لاگ
            $this->log_security_event('ip_blocked', array(
                'ip' => $ip,
                'reason' => 'تلاش‌های ناموفق متعدد برای ورود'
            ));
        }
    }

    /**
     * دریافت IP کاربر
     */
    private function get_client_ip() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP);
    }

    /**
     * بررسی endpoint گارانتی
     */
    private function is_warranty_endpoint() {
        if (isset($_SERVER['REQUEST_URI'])) {
            return strpos($_SERVER['REQUEST_URI'], '/warranty') !== false ||
                   strpos($_SERVER['REQUEST_URI'], '/guarantee') !== false;
        }
        return false;
    }

    /**
     * ثبت رویدادهای امنیتی
     */
    private function log_security_event($event, $data = array()) {
        $log = array(
            'event' => $event,
            'data' => $data,
            'ip' => $this->get_client_ip(),
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql')
        );

        $this->db->insert(
            $this->db->prefix . 'asg_logs',
            array(
                'action' => 'security_' . $event,
                'details' => maybe_serialize($log),
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s')
        );
    }

    /**
     * پاکسازی لاگ‌های قدیمی
     */
    public function cleanup_logs() {
        $days = !empty($this->settings['security_log_retention']) ? 
                intval($this->settings['security_log_retention']) : 30;

        $this->db->query(
            $this->db->prepare(
                "DELETE FROM {$this->db->prefix}asg_logs 
                 WHERE action LIKE 'security_%' 
                 AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}