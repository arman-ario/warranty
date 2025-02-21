<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

class ASG_Charts {
    private static $instance = null;
    private $wpdb;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'warranty-management-charts') === false) {
            return;
        }

        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', array(), null, true);
    }

    public function render_page() {
        // استایل‌های درون صفحه
        $this->render_styles();
        
        echo '<div class="wrap">';
        echo '<h1>نمودارهای گارانتی</h1>';
        echo '<div class="asg-reports-container">';
        
        $this->render_status_chart();
        $this->render_monthly_chart();
        
        echo '</div>'; // پایان container
        echo '</div>'; // پایان wrap

        $this->render_charts_scripts();
    }

    private function render_styles() {
        echo '<style>
            .asg-reports-container {
                margin: 20px 0;
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
            }
            .asg-report-card {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .asg-report-card.full-width {
                grid-column: 1 / -1;
            }
        </style>';
    }

    private function render_status_chart() {
        echo '<div class="asg-report-card">';
        echo '<h2>نمودار وضعیت‌ها</h2>';
        echo '<canvas id="statusChart"></canvas>';
        echo '</div>';
    }

    private function render_monthly_chart() {
        echo '<div class="asg-report-card">';
        echo '<h2>نمودار ماهانه درخواست‌ها</h2>';
        echo '<canvas id="monthlyChart"></canvas>';
        echo '</div>';
    }

    private function get_status_data() {
        $statuses = get_option('asg_statuses', array(
            'آماده ارسال',
            'ارسال شده',
            'تعویض شده',
            'خارج از گارانتی'
        ));
        
        $status_counts = array_fill_keys($statuses, 0);
        
        $status_data = $this->wpdb->get_results("
            SELECT status, COUNT(*) as count
            FROM {$this->wpdb->prefix}asg_guarantee_requests
            GROUP BY status
        ");

        foreach ($status_data as $data) {
            if (isset($status_counts[$data->status])) {
                $status_counts[$data->status] = $data->count;
            }
        }

        return array(
            'labels' => array_keys($status_counts),
            'data' => array_values($status_counts)
        );
    }

    private function get_monthly_data() {
        $monthly_data = $this->wpdb->get_results("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as count
            FROM {$this->wpdb->prefix}asg_guarantee_requests
            GROUP BY month
            ORDER BY month DESC
            LIMIT 12
        ");

        $labels = array();
        $counts = array();
        foreach (array_reverse($monthly_data) as $data) {
            $labels[] = $data->month;
            $counts[] = $data->count;
        }

        return array(
            'labels' => $labels,
            'data' => $counts
        );
    }

    private function render_charts_scripts() {
        $status_data = $this->get_status_data();
        $monthly_data = $this->get_monthly_data();

        ?>
        <script>
        jQuery(document).ready(function($) {
            // نمودار وضعیت‌ها
            new Chart(document.getElementById('statusChart'), {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode($status_data['labels']); ?>,
                    datasets: [{
                        data: <?php echo json_encode($status_data['data']); ?>,
                        backgroundColor: [
                            '#ffc107',
                            '#17a2b8',
                            '#28a745',
                            '#dc3545',
                            '#6c757d',
                            '#007bff'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });

            // نمودار ماهانه
            new Chart(document.getElementById('monthlyChart'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthly_data['labels']); ?>,
                    datasets: [{
                        label: 'تعداد درخواست‌ها',
                        data: <?php echo json_encode($monthly_data['data']); ?>,
                        borderColor: '#007bff',
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
        });
        </script>
        <?php
    }

    public function get_chart_data_ajax() {
        check_ajax_referer('asg-charts-nonce', 'nonce');
        
        $chart_type = isset($_POST['chart_type']) ? sanitize_text_field($_POST['chart_type']) : '';
        
        switch ($chart_type) {
            case 'status':
                wp_send_json_success($this->get_status_data());
                break;
            case 'monthly':
                wp_send_json_success($this->get_monthly_data());
                break;
            default:
                wp_send_json_error('نوع نمودار نامعتبر است.');
        }
    }
}