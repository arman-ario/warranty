<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت دیتابیس افزونه
 */
class ASG_DB {
    private static $instance = null;
    private $db;
    private $tables;

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

        // تعریف نام جداول
        $this->tables = array(
            'guarantees' => $this->db->prefix . 'asg_guarantees',
            'guarantee_meta' => $this->db->prefix . 'asg_guarantee_meta',
            'logs' => $this->db->prefix . 'asg_logs'
        );
    }

    /**
     * ایجاد جداول دیتابیس
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        try {
            // جدول گارانتی‌ها
            $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}asg_guarantees (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                product_id bigint(20) NOT NULL,
                user_id bigint(20) NOT NULL,
                serial_number varchar(100) NOT NULL,
                purchase_date date NOT NULL,
                expiry_date date NOT NULL,
                status varchar(50) NOT NULL DEFAULT 'pending',
                notes text,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY product_id (product_id),
                KEY user_id (user_id),
                KEY serial_number (serial_number),
                KEY status (status)
            ) $charset_collate;";
            
            dbDelta($sql);

            // جدول متای گارانتی
            $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}asg_guarantee_meta (
                meta_id bigint(20) NOT NULL AUTO_INCREMENT,
                guarantee_id bigint(20) NOT NULL,
                meta_key varchar(255) NOT NULL,
                meta_value longtext,
                PRIMARY KEY  (meta_id),
                KEY guarantee_id (guarantee_id),
                KEY meta_key (meta_key(191))
            ) $charset_collate;";
            
            dbDelta($sql);

            // جدول لاگ‌ها
            $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}asg_logs (
                log_id bigint(20) NOT NULL AUTO_INCREMENT,
                guarantee_id bigint(20) DEFAULT NULL,
                user_id bigint(20) DEFAULT NULL,
                action varchar(100) NOT NULL,
                details longtext,
                created_at datetime NOT NULL,
                PRIMARY KEY  (log_id),
                KEY guarantee_id (guarantee_id),
                KEY user_id (user_id),
                KEY action (action)
            ) $charset_collate;";
            
            dbDelta($sql);

            // بررسی ایجاد موفق جداول
            foreach (array('asg_guarantees', 'asg_guarantee_meta', 'asg_logs') as $table) {
                $table_name = $wpdb->prefix . $table;
                if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                    throw new Exception("خطا در ایجاد جدول $table_name");
                }
            }

            return true;

        } catch (Exception $e) {
            error_log('ASG Database Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * افزودن گارانتی جدید
     */
    public function insert_guarantee($data) {
        try {
            if (!isset($data['product_id'], $data['user_id'], $data['serial_number'], $data['purchase_date'])) {
                throw new Exception('داده‌های ضروری وارد نشده‌اند');
            }

            $defaults = array(
                'status' => 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            );

            $data = wp_parse_args($data, $defaults);

            // محاسبه تاریخ انقضا
            if (!isset($data['expiry_date'])) {
                $warranty_duration = get_post_meta($data['product_id'], '_warranty_duration', true);
                if (!$warranty_duration) {
                    $warranty_duration = get_option('asg_settings')['default_warranty_duration'];
                }
                $data['expiry_date'] = date('Y-m-d', strtotime($data['purchase_date'] . " +{$warranty_duration} months"));
            }

            $inserted = $this->db->insert(
                $this->tables['guarantees'],
                array(
                    'product_id' => $data['product_id'],
                    'user_id' => $data['user_id'],
                    'serial_number' => $data['serial_number'],
                    'purchase_date' => $data['purchase_date'],
                    'expiry_date' => $data['expiry_date'],
                    'status' => $data['status'],
                    'notes' => isset($data['notes']) ? $data['notes'] : '',
                    'created_at' => $data['created_at'],
                    'updated_at' => $data['updated_at']
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
            );

            if (!$inserted) {
                throw new Exception($this->db->last_error);
            }

            $guarantee_id = $this->db->insert_id;

            // افزودن متا
            if (!empty($data['meta'])) {
                foreach ($data['meta'] as $key => $value) {
                    $this->add_guarantee_meta($guarantee_id, $key, $value);
                }
            }

            return $guarantee_id;

        } catch (Exception $e) {
            error_log('ASG Insert Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * بروزرسانی گارانتی
     */
    public function update_guarantee($id, $data) {
        try {
            $data['updated_at'] = current_time('mysql');

            $updated = $this->db->update(
                $this->tables['guarantees'],
                $data,
                array('id' => $id),
                null,
                array('%d')
            );

            if ($updated === false) {
                throw new Exception($this->db->last_error);
            }

            return true;

        } catch (Exception $e) {
            error_log('ASG Update Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * حذف گارانتی
     */
    public function delete_guarantee($id) {
        try {
            // حذف متا
            $this->db->delete($this->tables['guarantee_meta'], 
                            array('guarantee_id' => $id), 
                            array('%d'));

            // حذف لاگ‌ها
            $this->db->delete($this->tables['logs'], 
                            array('guarantee_id' => $id), 
                            array('%d'));

            // حذف گارانتی
            $deleted = $this->db->delete(
                $this->tables['guarantees'],
                array('id' => $id),
                array('%d')
            );

            if ($deleted === false) {
                throw new Exception($this->db->last_error);
            }

            return true;

        } catch (Exception $e) {
            error_log('ASG Delete Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * افزودن متای گارانتی
     */
    public function add_guarantee_meta($guarantee_id, $key, $value) {
        return $this->db->insert(
            $this->tables['guarantee_meta'],
            array(
                'guarantee_id' => $guarantee_id,
                'meta_key' => $key,
                'meta_value' => maybe_serialize($value)
            ),
            array('%d', '%s', '%s')
        );
    }

    /**
     * دریافت متای گارانتی
     */
    public function get_guarantee_meta($guarantee_id, $key) {
        $value = $this->db->get_var($this->db->prepare(
            "SELECT meta_value FROM {$this->tables['guarantee_meta']} 
             WHERE guarantee_id = %d AND meta_key = %s",
            $guarantee_id,
            $key
        ));
        
        return $value ? maybe_unserialize($value) : null;
    }
}
