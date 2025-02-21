<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت گزارشات افزونه
 */
class ASG_Reports {
    private static $instance = null;
    private $db;
    private $settings;
    private $cache;

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
        $this->cache = ASG_Cache::instance();

        $this->init_hooks();
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_reports_submenu'));
        add_action('wp_ajax_get_warranty_stats', array($this, 'get_warranty_stats'));
        add_action('wp_ajax_export_warranty_data', array($this, 'export_warranty_data'));
    }

    /**
     * افزودن زیرمنوی گزارشات
     */
    public function add_reports_submenu() {
        add_submenu_page(
            'warranty-management',
            'گزارشات گارانتی',
            'گزارشات',
            'manage_options',
            'warranty-management-reports',
            array($this, 'render_page')
        );
    }

    /**
     * نمایش صفحه گزارشات
     */
    public static function render_page() {
        include ASG_PLUGIN_DIR . 'templates/admin/reports.php';
    }

    /**
     * دریافت آمار گارانتی‌ها (Ajax)
     */
    public function get_warranty_stats() {
        check_ajax_referer('asg-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }

        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';

        $cache_key = 'warranty_stats_' . md5($date_from . $date_to);
        $stats = $this->cache->get($cache_key);

        if ($stats === false) {
            $stats = $this->generate_warranty_stats($date_from, $date_to);
            $this->cache->set($cache_key, $stats, 'reports', 3600); // کش برای یک ساعت
        }

        wp_send_json_success($stats);
    }

    /**
     * تولید آمار گارانتی‌ها
     */
    private function generate_warranty_stats($date_from, $date_to) {
        $where = array();
        $values = array();

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from;
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to;
        }

        $where_clause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        // آمار کلی
        $total_stats = $this->db->get_row(
            $this->db->prepare(
                "SELECT 
                    COUNT(*) as total_warranties,
                    COUNT(DISTINCT user_id) as unique_customers,
                    COUNT(DISTINCT product_id) as unique_products,
                    SUM(CASE WHEN status = 'در انتظار بررسی' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'تایید شده' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'رد شده' THEN 1 ELSE 0 END) as rejected,
                    SUM(CASE WHEN expiry_date < NOW() THEN 1 ELSE 0 END) as expired
                FROM {$this->db->prefix}asg_guarantee_requests" . $where_clause,
                $values
            ),
            ARRAY_A
        );

        // آمار بر اساس محصول
        $product_stats = $this->db->get_results(
            $this->db->prepare(
                "SELECT 
                    product_id,
                    COUNT(*) as total,
                    MIN(created_at) as first_warranty,
                    MAX(created_at) as last_warranty
                FROM {$this->db->prefix}asg_guarantee_requests" . 
                $where_clause . 
                " GROUP BY product_id
                ORDER BY total DESC
                LIMIT 10",
                $values
            )
        );

        // آمار روزانه
        $daily_stats = $this->db->get_results(
            $this->db->prepare(
                "SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'تایید شده' THEN 1 ELSE 0 END) as approved
                FROM {$this->db->prefix}asg_guarantee_requests" .
                $where_clause .
                " GROUP BY DATE(created_at)
                ORDER BY date DESC
                LIMIT 30",
                $values
            )
        );

        // تکمیل اطلاعات محصولات
        foreach ($product_stats as &$stat) {
            $product = wc_get_product($stat->product_id);
            if ($product) {
                $stat->product_name = $product->get_name();
                $stat->product_sku = $product->get_sku();
            }
        }

        return array(
            'total' => $total_stats,
            'products' => $product_stats,
            'daily' => $daily_stats
        );
    }

    /**
     * خروجی اکسل گارانتی‌ها
     */
    public function export_warranty_data() {
        check_ajax_referer('asg-admin-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }

        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        $where = array();
        $values = array();

        if ($date_from) {
            $where[] = 'g.created_at >= %s';
            $values[] = $date_from;
        }

        if ($date_to) {
            $where[] = 'g.created_at <= %s';
            $values[] = $date_to;
        }

        if ($status) {
            $where[] = 'g.status = %s';
            $values[] = $status;
        }

        $where_clause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        $warranties = $this->db->get_results(
            $this->db->prepare(
                "SELECT 
                    g.*,
                    u.display_name as customer_name,
                    u.user_email as customer_email,
                    p.post_title as product_name,
                    pm.meta_value as product_sku
                FROM {$this->db->prefix}asg_guarantee_requests g
                LEFT JOIN {$this->db->prefix}users u ON g.user_id = u.ID
                LEFT JOIN {$this->db->prefix}posts p ON g.product_id = p.ID
                LEFT JOIN {$this->db->prefix}postmeta pm ON g.product_id = pm.post_id AND pm.meta_key = '_sku'" .
                $where_clause .
                " ORDER BY g.created_at DESC",
                $values
            )
        );

        // ایجاد فایل اکسل
        require_once ASG_PLUGIN_DIR . 'includes/libraries/PHPExcel.php';
        $excel = new PHPExcel();

        // تنظیم هدرها
        $headers = array(
            'شناسه',
            'نام مشتری',
            'ایمیل مشتری',
            'محصول',
            'کد محصول',
            'شماره سریال',
            'تاریخ خرید',
            'تاریخ انقضا',
            'وضعیت',
            'تاریخ ثبت'
        );

        $excel->setActiveSheetIndex(0);
        $sheet = $excel->getActiveSheet();
        
        // تنظیم عنوان‌ها
        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col, 1, $header);
        }

        // افزودن داده‌ها
        foreach ($warranties as $row => $warranty) {
            $sheet->setCellValueByColumnAndRow(0, $row + 2, $warranty->id);
            $sheet->setCellValueByColumnAndRow(1, $row + 2, $warranty->customer_name);
            $sheet->setCellValueByColumnAndRow(2, $row + 2, $warranty->customer_email);
            $sheet->setCellValueByColumnAndRow(3, $row + 2, $warranty->product_name);
            $sheet->setCellValueByColumnAndRow(4, $row + 2, $warranty->product_sku);
            $sheet->setCellValueByColumnAndRow(5, $row + 2, $warranty->serial_number);
            $sheet->setCellValueByColumnAndRow(6, $row + 2, $warranty->purchase_date);
            $sheet->setCellValueByColumnAndRow(7, $row + 2, $warranty->expiry_date);
            $sheet->setCellValueByColumnAndRow(8, $row + 2, $warranty->status);
            $sheet->setCellValueByColumnAndRow(9, $row + 2, $warranty->created_at);
        }

        // تنظیم عرض ستون‌ها
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // ذخیره فایل
        $filename = 'warranty-report-' . date('Y-m-d') . '.xlsx';
        $filepath = wp_upload_dir()['path'] . '/' . $filename;
        
        $writer = PHPExcel_IOFactory::createWriter($excel, 'Excel2007');
        $writer->save($filepath);

        wp_send_json_success(array(
            'file_url' => wp_upload_dir()['url'] . '/' . $filename
        ));
    }

    /**
     * دریافت گزارش عملکرد گارانتی‌ها
     */
    public function get_performance_report($date_from = '', $date_to = '') {
        $where = array();
        $values = array();

        if ($date_from) {
            $where[] = 'created_at >= %s';
            $values[] = $date_from;
        }

        if ($date_to) {
            $where[] = 'created_at <= %s';
            $values[] = $date_to;
        }

        $where_clause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        return $this->db->get_row(
            $this->db->prepare(
                "SELECT 
                    COUNT(*) as total_requests,
                    AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_processing_time,
                    SUM(CASE WHEN status = 'تایید شده' THEN 1 ELSE 0 END) / COUNT(*) * 100 as approval_rate,
                    COUNT(DISTINCT user_id) / COUNT(*) * 100 as customer_retention_rate
                FROM {$this->db->prefix}asg_guarantee_requests" . $where_clause,
                $values
            )
        );
    }
}