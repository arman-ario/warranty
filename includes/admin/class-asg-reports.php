<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت گزارشات افزونه گارانتی
 */
class ASG_Reports {
    private static $instance = null;
    private $wpdb;

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
        $this->wpdb = $wpdb;
        
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_asg_get_report_data', array($this, 'ajax_get_report_data'));
    }

    /**
     * افزودن فایل‌های CSS و JS
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'warranty-management-reports') === false) {
            return;
        }

        // Select2
        wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array('jquery'));

        // Chart.js
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array());

        // استایل‌های اختصاصی
        wp_add_inline_style('admin-bar', $this->get_custom_styles());
    }

    /**
     * استایل‌های اختصاصی
     */
    private function get_custom_styles() {
        return '
            .asg-reports-container {
                margin: 20px 0;
            }
            .asg-filter-row {
                display: flex;
                gap: 15px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }
            .asg-filter {
                flex: 1;
                min-width: 200px;
            }
            .asg-filter label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            .asg-report-card {
                background: white;
                padding: 20px;
                border-radius: 4px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            .asg-export-buttons {
                margin: 20px 0;
                display: flex;
                gap: 10px;
            }
            .asg-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
            .status-pending { background: #fff6d9; color: #856404; }
            .status-approved { background: #e5f5e8; color: #155724; }
            .status-rejected { background: #ffebee; color: #721c24; }
        ';
    }

    /**
     * صفحه اصلی گزارشات
     */
    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.'));
        }

        // دریافت پارامترهای فیلتر
        $filters = $this->get_filter_params();

        echo '<div class="wrap">';
        echo '<h1>گزارشات گارانتی</h1>';

        // فرم فیلترها
        $this->render_filters($filters);

        // نمایش نتایج
        $results = $this->get_report_data($filters);
        $this->render_results($results);

        echo '</div>';
    }

    /**
     * دریافت پارامترهای فیلتر از URL
     */
    private function get_filter_params() {
        return array(
            'customer' => isset($_GET['filter_customer']) ? intval($_GET['filter_customer']) : '',
            'product' => isset($_GET['filter_product']) ? intval($_GET['filter_product']) : '',
            'status' => isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '',
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
        );
    }

    /**
     * نمایش فرم فیلترها
     */
    private function render_filters($filters) {
        echo '<div class="asg-report-card">';
        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="warranty-management-reports">';

        echo '<div class="asg-filter-row">';
        
        // فیلتر مشتری
        echo '<div class="asg-filter">';
        echo '<label>مشتری:</label>';
        echo '<select name="filter_customer" class="asg-select2-customer" style="width: 100%;">';
        if (!empty($filters['customer'])) {
            $user = get_user_by('id', $filters['customer']);
            if ($user) {
                echo '<option value="' . esc_attr($user->ID) . '" selected>' . 
                     esc_html($user->display_name) . '</option>';
            }
        }
        echo '</select>';
        echo '</div>';

        // فیلتر محصول
        echo '<div class="asg-filter">';
        echo '<label>محصول:</label>';
        echo '<select name="filter_product" class="asg-select2-product" style="width: 100%;">';
        if (!empty($filters['product'])) {
            $product = wc_get_product($filters['product']);
            if ($product) {
                echo '<option value="' . esc_attr($product->get_id()) . '" selected>' . 
                     esc_html($product->get_name()) . '</option>';
            }
        }
        echo '</select>';
        echo '</div>';

        // فیلتر وضعیت
        echo '<div class="asg-filter">';
        echo '<label>وضعیت:</label>';
        echo '<select name="filter_status" style="width: 100%;">';
        echo '<option value="">همه وضعیت‌ها</option>';
        $statuses = get_option('asg_statuses', array(
            'آماده ارسال',
            'ارسال شده',
            'تعویض شده',
            'خارج از گارانتی'
        ));
        foreach ($statuses as $status) {
            $selected = ($status === $filters['status']) ? 'selected' : '';
            echo '<option value="' . esc_attr($status) . '" ' . $selected . '>' . 
                 esc_html($status) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '</div>'; // پایان filter-row اول

        echo '<div class="asg-filter-row">';
        
        // فیلتر تاریخ
        echo '<div class="asg-filter">';
        echo '<label>از تاریخ:</label>';
        echo '<input type="date" name="date_from" value="' . esc_attr($filters['date_from']) . 
             '" style="width: 100%;">';
        echo '</div>';

        echo '<div class="asg-filter">';
        echo '<label>تا تاریخ:</label>';
        echo '<input type="date" name="date_to" value="' . esc_attr($filters['date_to']) . 
             '" style="width: 100%;">';
        echo '</div>';

        echo '</div>'; // پایان filter-row دوم

        echo '<div class="asg-export-buttons">';
        echo '<button type="submit" class="button button-primary">اعمال فیلترها</button>';
        echo '<button type="button" class="button" onclick="window.location.href=\'' . 
             admin_url('admin.php?page=warranty-management-reports') . '\'">حذف فیلترها</button>';
        echo '<button type="button" class="button" onclick="window.print();">';
        echo '<span class="dashicons dashicons-printer"></span> چاپ گزارش</button>';
        echo '<a href="' . esc_url($this->get_export_url($filters)) . '" class="button">';
        echo '<span class="dashicons dashicons-media-spreadsheet"></span> خروجی اکسل</a>';
        echo '</div>';

        echo '</form>';
        echo '</div>'; // پایان report-card
    }

    /**
     * دریافت داده‌های گزارش
     */
    private function get_report_data($filters) {
        $where = array();
        $params = array();

        if (!empty($filters['customer'])) {
            $where[] = 'r.user_id = %d';
            $params[] = $filters['customer'];
        }

        if (!empty($filters['product'])) {
            $where[] = 'r.product_id = %d';
            $params[] = $filters['product'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'r.status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'r.created_at >= %s';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'r.created_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT r.*, 
                   p.post_title as product_name,
                   u.display_name as customer_name,
                   (SELECT COUNT(*) FROM {$this->wpdb->prefix}asg_guarantee_notes n 
                    WHERE n.request_id = r.id) as notes_count
            FROM {$this->wpdb->prefix}asg_guarantee_requests r
            LEFT JOIN {$this->wpdb->posts} p ON r.product_id = p.ID
            LEFT JOIN {$this->wpdb->users} u ON r.user_id = u.ID
            $where_sql
            ORDER BY r.created_at DESC
            LIMIT 500
        ";

        if (!empty($params)) {
            $sql = $this->wpdb->prepare($sql, $params);
        }

        return $this->wpdb->get_results($sql);
    }

    /**
     * نمایش نتایج گزارش
     */
    private function render_results($results) {
        if (empty($results)) {
            echo '<div class="asg-report-card">';
            echo '<p>هیچ نتیجه‌ای یافت نشد.</p>';
            echo '</div>';
            return;
        }

        echo '<div class="asg-report-card">';
        echo '<table class="wp-list-table widefat fixed striped">';
        
        // هدر جدول
        echo '<thead><tr>';
        echo '<th>شماره</th>';
        echo '<th>محصول</th>';
        echo '<th>مشتری</th>';
        echo '<th>وضعیت</th>';
        echo '<th>تاریخ ثبت</th>';
        echo '<th>یادداشت‌ها</th>';
        echo '<th>عملیات</th>';
        echo '</tr></thead>';

        // بدنه جدول
        echo '<tbody>';
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($row->product_name) . '</td>';
            echo '<td>' . esc_html($row->customer_name) . '</td>';
            echo '<td><span class="asg-status-badge status-' . 
                 esc_attr(strtolower($row->status)) . '">' . 
                 esc_html($row->status) . '</span></td>';
            echo '<td>' . date_i18n('Y/m/d H:i', strtotime($row->created_at)) . '</td>';
            echo '<td>' . esc_html($row->notes_count) . '</td>';
            echo '<td>';
            echo '<a href="' . admin_url('admin.php?page=warranty-management-edit&id=' . $row->id) . 
                 '" class="button button-small">مشاهده</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        
        echo '</table>';
        echo '</div>';
    }

    /**
     * ساخت URL خروجی اکسل
     */
    private function get_export_url($filters) {
        $params = array_merge(
            array('page' => 'warranty-management-reports', 'export' => 'excel'),
            $filters
        );
        return add_query_arg($params, admin_url('admin.php'));
    }

    /**
     * پردازش درخواست AJAX برای دریافت داده‌ها
     */
    public function ajax_get_report_data() {
        check_ajax_referer('asg-reports-nonce', 'nonce');
        
        $filters = $this->get_filter_params();
        $results = $this->get_report_data($filters);
        
        wp_send_json_success($results);
    }

    /**
     * تولید خروجی اکسل
     */
    public function generate_excel_export($filters) {
        $results = $this->get_report_data($filters);

        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment;filename=warranty-report-' . date('Y-m-d') . '.xls');
        header('Cache-Control: max-age=0');
        
        echo chr(0xEF) . chr(0xBB) . chr(0xBF); // UTF-8 BOM

        echo '<table border="1">';
        echo '<tr>';
        echo '<th>شماره</th>';
        echo '<th>محصول</th>';
        echo '<th>مشتری</th>';
        echo '<th>وضعیت</th>';
        echo '<th>تاریخ ثبت</th>';
        echo '<th>تعداد یادداشت‌ها</th>';
        echo '</tr>';
                // ادامه متد generate_excel_export
        foreach ($results as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->id) . '</td>';
            echo '<td>' . esc_html($row->product_name) . '</td>';
            echo '<td>' . esc_html($row->customer_name) . '</td>';
            echo '<td>' . esc_html($row->status) . '</td>';
            echo '<td>' . date_i18n('Y/m/d H:i', strtotime($row->created_at)) . '</td>';
            echo '<td>' . esc_html($row->notes_count) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        exit;
    }

    /**
     * دریافت آمار کلی
     */
    public function get_summary_stats() {
        $stats = $this->wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'آماده ارسال' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'ارسال شده' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'تعویض شده' THEN 1 ELSE 0 END) as replaced,
                SUM(CASE WHEN status = 'خارج از گارانتی' THEN 1 ELSE 0 END) as expired
            FROM {$this->wpdb->prefix}asg_guarantee_requests
        ");

        return array(
            'total' => number_format_i18n($stats->total),
            'pending' => number_format_i18n($stats->pending),
            'sent' => number_format_i18n($stats->sent),
            'replaced' => number_format_i18n($stats->replaced),
            'expired' => number_format_i18n($stats->expired)
        );
    }

    /**
     * دریافت آمار ماهانه
     */
    public function get_monthly_stats() {
        return $this->wpdb->get_results("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as count
            FROM {$this->wpdb->prefix}asg_guarantee_requests
            GROUP BY month
            ORDER BY month DESC
            LIMIT 12
        ");
    }

    /**
     * نمایش نمودار وضعیت‌ها
     */
    private function render_status_chart() {
        $stats = $this->get_summary_stats();
        
        echo '<div class="asg-report-card">';
        echo '<h3>نمودار وضعیت‌ها</h3>';
        echo '<canvas id="statusChart"></canvas>';
        
        $labels = array('آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی');
        $data = array($stats['pending'], $stats['sent'], $stats['replaced'], $stats['expired']);
        
        echo '<script>
        new Chart(document.getElementById("statusChart"), {
            type: "pie",
            data: {
                labels: ' . json_encode($labels) . ',
                datasets: [{
                    data: ' . json_encode($data) . ',
                    backgroundColor: ["#ffc107", "#17a2b8", "#28a745", "#dc3545"]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: "bottom"
                    }
                }
            }
        });
        </script>';
        echo '</div>';
    }

    /**
     * نمایش نمودار ماهانه
     */
    private function render_monthly_chart() {
        $monthly_stats = $this->get_monthly_stats();
        
        $labels = array();
        $data = array();
        foreach (array_reverse($monthly_stats) as $stat) {
            $labels[] = $this->convert_to_jalali_month($stat->month);
            $data[] = $stat->count;
        }

        echo '<div class="asg-report-card">';
        echo '<h3>نمودار ماهانه</h3>';
        echo '<canvas id="monthlyChart"></canvas>';
        
        echo '<script>
        new Chart(document.getElementById("monthlyChart"), {
            type: "line",
            data: {
                labels: ' . json_encode($labels) . ',
                datasets: [{
                    label: "تعداد درخواست‌ها",
                    data: ' . json_encode($data) . ',
                    borderColor: "#007bff",
                    fill: false
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        </script>';
        echo '</div>';
    }

    /**
     * تبدیل تاریخ میلادی به شمسی
     */
    private function convert_to_jalali_month($date) {
        list($year, $month) = explode('-', $date);
        $months = array(
            'فروردین', 'اردیبهشت', 'خرداد', 
            'تیر', 'مرداد', 'شهریور',
            'مهر', 'آبان', 'آذر', 
            'دی', 'بهمن', 'اسفند'
        );
        
        $jDate = gregorian_to_jalali($year, $month, 1);
        return $months[$jDate[1] - 1] . ' ' . $jDate[0];
    }
}