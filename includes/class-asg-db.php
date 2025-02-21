<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

class ASG_DB {
    private static $instance = null;
    private $wpdb;
    private $tables;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tables = [
            'guarantees' => $wpdb->prefix . 'asg_guarantees',
            'meta' => $wpdb->prefix . 'asg_meta',
            'logs' => $wpdb->prefix . 'asg_logs'
        ];
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
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY product_id (product_id),
                KEY user_id (user_id),
                KEY serial_number (serial_number),
                KEY status (status)
            ) $charset_collate;";
            
            dbDelta($sql);

            // جدول متا
            $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}asg_meta (
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
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (log_id),
                KEY guarantee_id (guarantee_id),
                KEY user_id (user_id),
                KEY action (action)
            ) $charset_collate;";
            
            dbDelta($sql);

            return true;

        } catch (Exception $e) {
            error_log('ASG Database Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * افزودن گارانتی جدید
     */
    public function add_guarantee($data) {
        try {
            $defaults = [
                'status' => 'pending',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ];

            $data = wp_parse_args($data, $defaults);

            $result = $this->wpdb->insert(
                $this->tables['guarantees'],
                [
                    'product_id' => $data['product_id'],
                    'user_id' => $data['user_id'],
                    'serial_number' => $data['serial_number'],
                    'purchase_date' => $data['purchase_date'],
                    'expiry_date' => $data['expiry_date'],
                    'status' => $data['status'],
                    'notes' => $data['notes'] ?? '',
                    'created_at' => $data['created_at'],
                    'updated_at' => $data['updated_at']
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            if ($result === false) {
                throw new Exception($this->wpdb->last_error);
            }

            return $this->wpdb->insert_id;

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

            $result = $this->wpdb->update(
                $this->tables['guarantees'],
                $data,
                ['id' => $id],
                null,
                ['%d']
            );

            if ($result === false) {
                throw new Exception($this->wpdb->last_error);
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
            $this->wpdb->delete($this->tables['meta'], ['guarantee_id' => $id]);
            
            // حذف لاگ‌ها
            $this->wpdb->delete($this->tables['logs'], ['guarantee_id' => $id]);
            
            // حذف گارانتی
            $result = $this->wpdb->delete(
                $this->tables['guarantees'],
                ['id' => $id],
                ['%d']
            );

            if ($result === false) {
                throw new Exception($this->wpdb->last_error);
            }

            return true;

        } catch (Exception $e) {
            error_log('ASG Delete Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * بررسی وجود جداول
     */
    public function check_tables() {
        $tables = [
            $this->wpdb->prefix . 'asg_guarantees',
            $this->wpdb->prefix . 'asg_meta',
            $this->wpdb->prefix . 'asg_logs'
        ];

        foreach ($tables as $table) {
            $table_exists = $this->wpdb->get_var(
                $this->wpdb->prepare("SHOW TABLES LIKE %s", $table)
            );

            if (!$table_exists) {
                return false;
            }
        }

        return true;
    }
}