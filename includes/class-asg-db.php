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
    private $charset_collate;

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
        $this->charset_collate = $this->db->get_charset_collate();
        
        // تعریف جدول‌های افزونه
        $this->tables = array(
            'guarantees' => $this->db->prefix . 'asg_guarantee_requests',
            'logs' => $this->db->prefix . 'asg_logs',
            'meta' => $this->db->prefix . 'asg_meta'
        );
    }

    /**
     * ایجاد جداول دیتابیس
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // جدول درخواست‌های گارانتی
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}asg_guarantee_requests (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            serial_number varchar(100) NOT NULL,
            purchase_date date NOT NULL,
            expiry_date date NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'در انتظار بررسی',
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

        // جدول لاگ‌ها
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}asg_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            guarantee_id bigint(20) NOT NULL,
            user_id bigint(20) NOT NULL,
            action varchar(50) NOT NULL,
            details text,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY guarantee_id (guarantee_id),
            KEY user_id (user_id),
            KEY action (action)
        ) $charset_collate;";
        dbDelta($sql);

        // جدول متا
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}asg_meta (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            guarantee_id bigint(20) NOT NULL,
            meta_key varchar(255) NOT NULL,
            meta_value longtext,
            PRIMARY KEY  (id),
            KEY guarantee_id (guarantee_id),
            KEY meta_key (meta_key(191))
        ) $charset_collate;";
        dbDelta($sql);
    }

    /**
     * حذف جداول دیتابیس
     */
    public static function drop_tables() {
        global $wpdb;
        
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}asg_guarantee_requests");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}asg_logs");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}asg_meta");
    }

    /**
     * درج گارانتی جدید
     */
    public function insert_guarantee($data) {
        $defaults = array(
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );

        $data = wp_parse_args($data, $defaults);

        // محاسبه تاریخ انقضا
        if (!isset($data['expiry_date']) && isset($data['purchase_date'])) {
            $warranty_duration = get_option('asg_settings')['default_warranty_duration'];
            $data['expiry_date'] = date('Y-m-d', strtotime($data['purchase_date'] . " +{$warranty_duration} months"));
        }

        $result = $this->db->insert(
            $this->tables['guarantees'],
            $data,
            array(
                '%d', // product_id
                '%d', // user_id
                '%s', // serial_number
                '%s', // purchase_date
                '%s', // expiry_date
                '%s', // status
                '%s', // notes
                '%s', // created_at
                '%s'  // updated_at
            )
        );

        if ($result) {
            $guarantee_id = $this->db->insert_id;
            
            // ثبت لاگ
            $this->log_action($guarantee_id, 'create', 'گارانتی جدید ایجاد شد');
            
            return $guarantee_id;
        }

        return false;
    }

    /**
     * بروزرسانی گارانتی
     */
    public function update_guarantee($id, $data) {
        $data['updated_at'] = current_time('mysql');

        $result = $this->db->update(
            $this->tables['guarantees'],
            $data,
            array('id' => $id),
            array(
                '%s', // status
                '%s', // notes
                '%s'  // updated_at
            ),
            array('%d')
        );

        if ($result) {
            // ثبت لاگ
            $this->log_action($id, 'update', 'گارانتی بروزرسانی شد');
            return true;
        }

        return false;
    }

    /**
     * حذف گارانتی
     */
    public function delete_guarantee($id) {
        // حذف متاها
        $this->db->delete($this->tables['meta'], array('guarantee_id' => $id), array('%d'));
        
        // حذف لاگ‌ها
        $this->db->delete($this->tables['logs'], array('guarantee_id' => $id), array('%d'));
        
        // حذف گارانتی
        return $this->db->delete(
            $this->tables['guarantees'],
            array('id' => $id),
            array('%d')
        );
    }

    /**
     * دریافت گارانتی
     */
    public function get_guarantee($id) {
        return $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->tables['guarantees']} WHERE id = %d",
                $id
            )
        );
    }

    /**
     * جستجوی گارانتی‌ها
     */
    public function search_guarantees($args = array()) {
        $defaults = array(
            'page' => 1,
            'per_page' => 10,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'status' => '',
            'product_id' => '',
            'user_id' => '',
            'search' => '',
            'date_from' => '',
            'date_to' => ''
        );

        $args = wp_parse_args($args, $defaults);
        $offset = ($args['page'] - 1) * $args['per_page'];
        
        $where = array();
        $values = array();

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if (!empty($args['product_id'])) {
            $where[] = 'product_id = %d';
            $values[] = $args['product_id'];
        }

        if (!empty($args['user_id'])) {
            $where[] = 'user_id = %d';
            $values[] = $args['user_id'];
        }

        if (!empty($args['search'])) {
            $where[] = '(serial_number LIKE %s OR notes LIKE %s)';
            $values[] = '%' . $this->db->esc_like($args['search']) . '%';
            $values[] = '%' . $this->db->esc_like($args['search']) . '%';
        }

        if (!empty($args['date_from'])) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'];
        }

        if (!empty($args['date_to'])) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT * FROM {$this->tables['guarantees']} 
                 $where_clause
                 ORDER BY {$args['orderby']} {$args['order']}
                 LIMIT %d OFFSET %d";

        $values[] = $args['per_page'];
        $values[] = $offset;

        return array(
            'items' => $this->db->get_results($this->db->prepare($query, $values)),
            'total' => $this->get_total_guarantees($where, array_slice($values, 0, -2))
        );
    }

    /**
     * دریافت تعداد کل گارانتی‌ها
     */
    private function get_total_guarantees($where = array(), $values = array()) {
        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $query = "SELECT COUNT(*) FROM {$this->tables['guarantees']} $where_clause";
        
        return $this->db->get_var($this->db->prepare($query, $values));
    }

    /**
     * ثبت لاگ
     */
    private function log_action($guarantee_id, $action, $details = '') {
        return $this->db->insert(
            $this->tables['logs'],
            array(
                'guarantee_id' => $guarantee_id,
                'user_id' => get_current_user_id(),
                'action' => $action,
                'details' => $details,
                'created_at' => current_time('mysql')
            ),
            array(
                '%d', // guarantee_id
                '%d', // user_id
                '%s', // action
                '%s', // details
                '%s'  // created_at
            )
        );
    }

    /**
     * افزودن متا
     */
    public function add_meta($guarantee_id, $key, $value) {
        return $this->db->insert(
            $this->tables['meta'],
            array(
                'guarantee_id' => $guarantee_id,
                'meta_key' => $key,
                'meta_value' => maybe_serialize($value)
            ),
            array('%d', '%s', '%s')
        );
    }

    /**
     * دریافت متا
     */
    public function get_meta($guarantee_id, $key) {
        $value = $this->db->get_var(
            $this->db->prepare(
                "SELECT meta_value FROM {$this->tables['meta']} 
                 WHERE guarantee_id = %d AND meta_key = %s",
                $guarantee_id,
                $key
            )
        );
        
        return maybe_unserialize($value);
    }

    /**
     * بروزرسانی متا
     */
    public function update_meta($guarantee_id, $key, $value) {
        $current = $this->get_meta($guarantee_id, $key);
        
        if (is_null($current)) {
            return $this->add_meta($guarantee_id, $key, $value);
        }

        return $this->db->update(
            $this->tables['meta'],
            array('meta_value' => maybe_serialize($value)),
            array(
                'guarantee_id' => $guarantee_id,
                'meta_key' => $key
            ),
            array('%s'),
            array('%d', '%s')
        );
    }

    /**
     * حذف متا
     */
    public function delete_meta($guarantee_id, $key) {
        return $this->db->delete(
            $this->tables['meta'],
            array(
                'guarantee_id' => $guarantee_id,
                'meta_key' => $key
            ),
            array('%d', '%s')
        );
    }
}