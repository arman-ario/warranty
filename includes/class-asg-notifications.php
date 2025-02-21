<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت نوتیفیکیشن‌های افزونه
 */
class ASG_Notifications {
    private static $instance = null;
    private $settings;
    private $db;
    private $mailer;

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
        $this->mailer = new ASG_Mailer();

        $this->init_hooks();
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function init_hooks() {
        // هوک‌های مربوط به گارانتی
        add_action('asg_warranty_created', array($this, 'warranty_created_notification'), 10, 2);
        add_action('asg_warranty_updated', array($this, 'warranty_updated_notification'), 10, 2);
        add_action('asg_warranty_status_changed', array($this, 'warranty_status_changed_notification'), 10, 3);
        add_action('asg_warranty_expiring_soon', array($this, 'warranty_expiring_notification'), 10, 1);
        
        // نوتیفیکیشن‌های ادمین
        add_action('admin_notices', array($this, 'admin_notifications'));
        
        // کرون جاب برای بررسی گارانتی‌های در حال انقضا
        add_action('asg_warranty_expiry_check', array($this, 'check_expiring_warranties'));
    }

    /**
     * ارسال نوتیفیکیشن ایجاد گارانتی جدید
     */
    public function warranty_created_notification($warranty_id, $data) {
        if (empty($this->settings['enable_notifications'])) {
            return;
        }

        // ارسال ایمیل به مشتری
        $user = get_user_by('id', $data['user_id']);
        $product = wc_get_product($data['product_id']);

        $this->mailer->send_warranty_created_email(
            $user->user_email,
            array(
                'warranty_id' => $warranty_id,
                'product_name' => $product->get_name(),
                'serial_number' => $data['serial_number'],
                'purchase_date' => $data['purchase_date'],
                'expiry_date' => $data['expiry_date']
            )
        );

        // ارسال ایمیل به ادمین
        $admin_email = get_option('admin_email');
        $this->mailer->send_admin_warranty_notification(
            $admin_email,
            'new_warranty',
            array(
                'warranty_id' => $warranty_id,
                'user_name' => $user->display_name,
                'product_name' => $product->get_name()
            )
        );

        // ذخیره در دیتابیس
        $this->log_notification($warranty_id, 'created', array(
            'user_id' => $data['user_id'],
            'product_id' => $data['product_id']
        ));
    }

    /**
     * ارسال نوتیفیکیشن بروزرسانی گارانتی
     */
    public function warranty_updated_notification($warranty_id, $data) {
        if (empty($this->settings['enable_notifications'])) {
            return;
        }

        $warranty = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}asg_guarantee_requests WHERE id = %d",
                $warranty_id
            )
        );

        if (!$warranty) {
            return;
        }

        $user = get_user_by('id', $warranty->user_id);
        $product = wc_get_product($warranty->product_id);

        // ارسال ایمیل به مشتری
        $this->mailer->send_warranty_updated_email(
            $user->user_email,
            array(
                'warranty_id' => $warranty_id,
                'product_name' => $product->get_name(),
                'status' => $data['status'] ?? $warranty->status,
                'notes' => $data['notes'] ?? ''
            )
        );

        // ذخیره در دیتابیس
        $this->log_notification($warranty_id, 'updated', $data);
    }

    /**
     * ارسال نوتیفیکیشن تغییر وضعیت گارانتی
     */
    public function warranty_status_changed_notification($warranty_id, $old_status, $new_status) {
        if (empty($this->settings['enable_notifications'])) {
            return;
        }

        $warranty = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}asg_guarantee_requests WHERE id = %d",
                $warranty_id
            )
        );

        if (!$warranty) {
            return;
        }

        $user = get_user_by('id', $warranty->user_id);
        $product = wc_get_product($warranty->product_id);

        // ارسال ایمیل به مشتری
        $this->mailer->send_warranty_status_email(
            $user->user_email,
            array(
                'warranty_id' => $warranty_id,
                'product_name' => $product->get_name(),
                'old_status' => $old_status,
                'new_status' => $new_status
            )
        );

        // ذخیره در دیتابیس
        $this->log_notification($warranty_id, 'status_changed', array(
            'old_status' => $old_status,
            'new_status' => $new_status
        ));
    }

    /**
     * ارسال نوتیفیکیشن انقضای گارانتی
     */
    public function warranty_expiring_notification($warranty_id) {
        if (empty($this->settings['enable_notifications'])) {
            return;
        }

        $warranty = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}asg_guarantee_requests WHERE id = %d",
                $warranty_id
            )
        );

        if (!$warranty) {
            return;
        }

        $user = get_user_by('id', $warranty->user_id);
        $product = wc_get_product($warranty->product_id);

        // ارسال ایمیل به مشتری
        $this->mailer->send_warranty_expiring_email(
            $user->user_email,
            array(
                'warranty_id' => $warranty_id,
                'product_name' => $product->get_name(),
                'expiry_date' => $warranty->expiry_date
            )
        );

        // ذخیره در دیتابیس
        $this->log_notification($warranty_id, 'expiring_soon', array(
            'expiry_date' => $warranty->expiry_date
        ));
    }

    /**
     * نمایش نوتیفیکیشن‌های ادمین
     */
    public function admin_notifications() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // بررسی تنظیمات ضروری
        if (empty($this->settings['default_warranty_duration'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo 'لطفا مدت زمان پیش‌فرض گارانتی را در تنظیمات مشخص کنید.';
            echo '</p></div>';
        }

        // نمایش گارانتی‌های در حال انقضا
        $expiring_warranties = $this->get_expiring_warranties();
        if (!empty($expiring_warranties)) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            echo sprintf(
                'تعداد %d گارانتی در حال انقضا است. ',
                count($expiring_warranties)
            );
            echo '<a href="' . admin_url('admin.php?page=warranty-management&filter=expiring') . '">مشاهده لیست</a>';
            echo '</p></div>';
        }
    }

    /**
     * بررسی گارانتی‌های در حال انقضا
     */
    public function check_expiring_warranties() {
        $expiring_days = !empty($this->settings['expiring_notification_days']) ? 
                        $this->settings['expiring_notification_days'] : 30;

        $warranties = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}asg_guarantee_requests
                 WHERE expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)
                 AND status NOT IN ('منقضی شده', 'لغو شده')",
                $expiring_days
            )
        );

        foreach ($warranties as $warranty) {
            do_action('asg_warranty_expiring_soon', $warranty->id);
        }
    }

    /**
     * دریافت گارانتی‌های در حال انقضا
     */
    private function get_expiring_warranties() {
        $expiring_days = !empty($this->settings['expiring_notification_days']) ? 
                        $this->settings['expiring_notification_days'] : 30;

        return $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}asg_guarantee_requests
                 WHERE expiry_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)
                 AND status NOT IN ('منقضی شده', 'لغو شده')",
                $expiring_days
            )
        );
    }

    /**
     * ذخیره لاگ نوتیفیکیشن
     */
    private function log_notification($warranty_id, $type, $data = array()) {
        $this->db->insert(
            $this->db->prefix . 'asg_logs',
            array(
                'guarantee_id' => $warranty_id,
                'user_id' => get_current_user_id(),
                'action' => 'notification_' . $type,
                'details' => maybe_serialize($data),
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s')
        );
    }
}

/**
 * کلاس کمکی برای ارسال ایمیل
 */
class ASG_Mailer {
    /**
     * ارسال ایمیل ایجاد گارانتی
     */
    public function send_warranty_created_email($to, $data) {
        $subject = 'گارانتی جدید ثبت شد';
        $message = $this->get_email_template('warranty_created', $data);
        return $this->send($to, $subject, $message);
    }

    /**
     * ارسال ایمیل بروزرسانی گارانتی
     */
    public function send_warranty_updated_email($to, $data) {
        $subject = 'بروزرسانی گارانتی';
        $message = $this->get_email_template('warranty_updated', $data);
        return $this->send($to, $subject, $message);
    }

    /**
     * ارسال ایمیل تغییر وضعیت
     */
    public function send_warranty_status_email($to, $data) {
        $subject = 'تغییر وضعیت گارانتی';
        $message = $this->get_email_template('warranty_status', $data);
        return $this->send($to, $subject, $message);
    }

    /**
     * ارسال ایمیل انقضای گارانتی
     */
    public function send_warranty_expiring_email($to, $data) {
        $subject = 'هشدار انقضای گارانتی';
        $message = $this->get_email_template('warranty_expiring', $data);
        return $this->send($to, $subject, $message);
    }

    /**
     * ارسال ایمیل به ادمین
     */
    public function send_admin_warranty_notification($to, $type, $data) {
        $subject = 'نوتیفیکیشن گارانتی - ' . $type;
        $message = $this->get_email_template('admin_' . $type, $data);
        return $this->send($to, $subject, $message);
    }

    /**
     * دریافت قالب ایمیل
     */
    private function get_email_template($template, $data) {
        ob_start();
        include ASG_PLUGIN_DIR . 'templates/emails/' . $template . '.php';
        return ob_get_clean();
    }

    /**
     * ارسال ایمیل
     */
    private function send($to, $subject, $message) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        return wp_mail($to, $subject, $message, $headers);
    }
}