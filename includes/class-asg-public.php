<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت بخش عمومی افزونه
 */
class ASG_Public {
    private static $instance = null;
    private $settings;
    private $db;

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
    }

    /**
     * راه‌اندازی هوک‌ها
     */
    private function init_hooks() {
        // افزودن شورت‌کدها
        add_shortcode('warranty_form', array($this, 'warranty_form_shortcode'));
        add_shortcode('warranty_status', array($this, 'warranty_status_shortcode'));
        add_shortcode('warranty_list', array($this, 'warranty_list_shortcode'));

        // اکشن‌های Ajax
        add_action('wp_ajax_check_warranty_status', array($this, 'check_warranty_status'));
        add_action('wp_ajax_nopriv_check_warranty_status', array($this, 'check_warranty_status'));
        add_action('wp_ajax_submit_warranty_form', array($this, 'handle_warranty_form'));
        add_action('wp_ajax_nopriv_submit_warranty_form', array($this, 'handle_warranty_form'));

        // افزودن فایل‌های CSS و JS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_public_assets'));

        // افزودن منو به My Account
        add_action('init', array($this, 'add_my_account_endpoints'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_warranty_menu_item'));
        add_action('woocommerce_account_warranties_endpoint', array($this, 'my_account_warranties_content'));
    }

    /**
     * افزودن فایل‌های CSS و JS
     */
    public function enqueue_public_assets() {
        // استایل‌ها
        wp_enqueue_style(
            'asg-public',
            ASG_PLUGIN_URL . 'assets/css/asg-public.css',
            array(),
            ASG_VERSION
        );

        wp_enqueue_style(
            'persian-datepicker',
            ASG_PLUGIN_URL . 'assets/css/persian-datepicker.min.css',
            array(),
            ASG_VERSION
        );

        // اسکریپت‌ها
        wp_enqueue_script(
            'persian-date',
            ASG_PLUGIN_URL . 'assets/js/persian-date.min.js',
            array('jquery'),
            ASG_VERSION,
            true
        );

        wp_enqueue_script(
            'persian-datepicker',
            ASG_PLUGIN_URL . 'assets/js/persian-datepicker.min.js',
            array('persian-date'),
            ASG_VERSION,
            true
        );

        wp_enqueue_script(
            'asg-public',
            ASG_PLUGIN_URL . 'assets/js/asg-public.js',
            array('jquery', 'persian-datepicker'),
            ASG_VERSION,
            true
        );

        // لوکال‌های جاوااسکریپت
        wp_localize_script('asg-public', 'asgPublic', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('asg-public-nonce'),
            'i18n' => array(
                'warrantyNotFound' => 'گارانتی یافت نشد.',
                'warrantyExpired' => 'گارانتی منقضی شده است.',
                'invalidSerial' => 'شماره سریال نامعتبر است.',
                'required' => 'این فیلد الزامی است.',
                'success' => 'عملیات با موفقیت انجام شد.',
                'error' => 'خطایی رخ داده است.'
            )
        ));
    }

    /**
     * شورت‌کد فرم ثبت گارانتی
     */
    public function warranty_form_shortcode($atts) {
        if (!is_user_logged_in() && empty($this->settings['allow_guest_registration'])) {
            return '<p class="asg-error">لطفا برای ثبت گارانتی وارد حساب کاربری خود شوید.</p>';
        }

        ob_start();
        include ASG_PLUGIN_DIR . 'templates/public/warranty-form.php';
        return ob_get_clean();
    }

    /**
     * شورت‌کد بررسی وضعیت گارانتی
     */
    public function warranty_status_shortcode($atts) {
        ob_start();
        include ASG_PLUGIN_DIR . 'templates/public/warranty-status.php';
        return ob_get_clean();
    }

    /**
     * شورت‌کد لیست گارانتی‌ها
     */
    public function warranty_list_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p class="asg-error">لطفا برای مشاهده لیست گارانتی‌ها وارد حساب کاربری خود شوید.</p>';
        }

        $atts = shortcode_atts(array(
            'per_page' => 10,
            'status' => '',
            'orderby' => 'created_at',
            'order' => 'DESC'
        ), $atts);

        ob_start();
        include ASG_PLUGIN_DIR . 'templates/public/warranty-list.php';
        return ob_get_clean();
    }

    /**
     * بررسی وضعیت گارانتی (Ajax)
     */
    public function check_warranty_status() {
        check_ajax_referer('asg-public-nonce', 'nonce');

        $serial_number = isset($_POST['serial_number']) ? 
                        sanitize_text_field($_POST['serial_number']) : '';

        if (empty($serial_number)) {
            wp_send_json_error('شماره سریال وارد نشده است.');
        }

        $warranty = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}asg_guarantee_requests 
                 WHERE serial_number = %s",
                $serial_number
            )
        );

        if (!$warranty) {
            wp_send_json_error('گارانتی با این شماره سریال یافت نشد.');
        }

        $product = wc_get_product($warranty->product_id);
        $response = array(
            'id' => $warranty->id,
            'product' => array(
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'image' => get_the_post_thumbnail_url($product->get_id(), 'thumbnail')
            ),
            'serial_number' => $warranty->serial_number,
            'purchase_date' => $warranty->purchase_date,
            'expiry_date' => $warranty->expiry_date,
            'status' => $warranty->status,
            'is_expired' => strtotime($warranty->expiry_date) < time()
        );

        wp_send_json_success($response);
    }

    /**
     * پردازش فرم ثبت گارانتی (Ajax)
     */
    public function handle_warranty_form() {
        check_ajax_referer('asg-public-nonce', 'nonce');

        if (!is_user_logged_in() && empty($this->settings['allow_guest_registration'])) {
            wp_send_json_error('دسترسی غیرمجاز');
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $serial_number = isset($_POST['serial_number']) ? 
                        sanitize_text_field($_POST['serial_number']) : '';
        $purchase_date = isset($_POST['purchase_date']) ? 
                        sanitize_text_field($_POST['purchase_date']) : '';

        // اعتبارسنجی
        if (!$product_id || !$serial_number || !$purchase_date) {
            wp_send_json_error('لطفا تمام فیلدهای ضروری را پر کنید.');
        }

        // بررسی تکراری نبودن شماره سریال
        $exists = $this->db->get_var(
            $this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->prefix}asg_guarantee_requests 
                 WHERE serial_number = %s",
                $serial_number
            )
        );

        if ($exists) {
            wp_send_json_error('این شماره سریال قبلاً ثبت شده است.');
        }

        // درج گارانتی
        $warranty_duration = get_post_meta($product_id, '_warranty_duration', true);
        if (!$warranty_duration) {
            $warranty_duration = $this->settings['default_warranty_duration'];
        }

        $expiry_date = date('Y-m-d', strtotime($purchase_date . " +{$warranty_duration} months"));

        $result = $this->db->insert(
            $this->db->prefix . 'asg_guarantee_requests',
            array(
                'product_id' => $product_id,
                'user_id' => get_current_user_id(),
                'serial_number' => $serial_number,
                'purchase_date' => $purchase_date,
                'expiry_date' => $expiry_date,
                'status' => 'در انتظار بررسی',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if (!$result) {
            wp_send_json_error('خطا در ثبت گارانتی');
        }

        $warranty_id = $this->db->insert_id;
        do_action('asg_warranty_created', $warranty_id);

        wp_send_json_success(array(
            'message' => 'گارانتی با موفقیت ثبت شد.',
            'warranty_id' => $warranty_id
        ));
    }

    /**
     * افزودن endpoint به My Account
     */
    public function add_my_account_endpoints() {
        add_rewrite_endpoint('warranties', EP_ROOT | EP_PAGES);
    }

    /**
     * افزودن منوی گارانتی‌ها به My Account
     */
    public function add_warranty_menu_item($items) {
        $new_items = array();
        
        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            if ($key === 'orders') {
                $new_items['warranties'] = 'گارانتی‌های من';
            }
        }
        
        return $new_items;
    }

    /**
     * نمایش محتوای صفحه گارانتی‌ها در My Account
     */
    public function my_account_warranties_content() {
        $user_id = get_current_user_id();
        
        $warranties = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->db->prefix}asg_guarantee_requests 
                 WHERE user_id = %d 
                 ORDER BY created_at DESC",
                $user_id
            )
        );

        include ASG_PLUGIN_DIR . 'templates/public/my-account-warranties.php';
    }
}