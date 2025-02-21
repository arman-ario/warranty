<?php
if (!defined('ABSPATH')) {
    exit('دسترسی مستقیم غیرمجاز است!');
}

/**
 * کلاس مدیریت API افزونه گارانتی
 */
class ASG_API {
    private static $instance = null;
    private $namespace = 'warranty/v1';
    private $rest_base = 'guarantees';

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
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('wp_ajax_asg_submit_warranty', array($this, 'handle_submit_warranty'));
        add_action('wp_ajax_asg_get_product_info', array($this, 'get_product_info'));
        add_action('wp_ajax_asg_update_status', array($this, 'update_warranty_status'));
    }

    /**
     * ثبت مسیرهای REST API
     */
    public function register_routes() {
        // دریافت لیست گارانتی‌ها
        register_rest_route($this->namespace, '/' . $this->rest_base, array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_items'),
                'permission_callback' => array($this, 'get_items_permissions_check'),
                'args' => $this->get_collection_params()
            ),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'create_item'),
                'permission_callback' => array($this, 'create_item_permissions_check'),
                'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::CREATABLE)
            )
        ));

        // دریافت/بروزرسانی/حذف یک گارانتی خاص
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_item'),
                'permission_callback' => array($this, 'get_item_permissions_check'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric($param);
                        }
                    )
                )
            ),
            array(
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => array($this, 'update_item'),
                'permission_callback' => array($this, 'update_item_permissions_check'),
                'args' => $this->get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE)
            ),
            array(
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => array($this, 'delete_item'),
                'permission_callback' => array($this, 'delete_item_permissions_check')
            )
        ));
    }

    /**
     * بررسی دسترسی برای دریافت لیست
     */
    public function get_items_permissions_check($request) {
        return current_user_can('read');
    }

    /**
     * بررسی دسترسی برای ایجاد آیتم
     */
    public function create_item_permissions_check($request) {
        return current_user_can('manage_options');
    }

    /**
     * بررسی دسترسی برای دریافت یک آیتم
     */
    public function get_item_permissions_check($request) {
        return current_user_can('read');
    }

    /**
     * بررسی دسترسی برای بروزرسانی آیتم
     */
    public function update_item_permissions_check($request) {
        return current_user_can('manage_options');
    }

    /**
     * بررسی دسترسی برای حذف آیتم
     */
    public function delete_item_permissions_check($request) {
        return current_user_can('manage_options');
    }

    /**
     * دریافت لیست گارانتی‌ها
     */
    public function get_items($request) {
        global $wpdb;

        $per_page = $request->get_param('per_page') ? $request->get_param('per_page') : 10;
        $page = $request->get_param('page') ? $request->get_param('page') : 1;
        $offset = ($page - 1) * $per_page;

        $where = array();
        $values = array();

        // اعمال فیلترها
        if ($request->get_param('status')) {
            $where[] = 'status = %s';
            $values[] = $request->get_param('status');
        }

        if ($request->get_param('product_id')) {
            $where[] = 'product_id = %d';
            $values[] = $request->get_param('product_id');
        }

        $where_clause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}asg_guarantee_requests
                 $where_clause
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                array_merge($values, array($per_page, $offset))
            )
        );

        return new WP_REST_Response($items, 200);
    }

    /**
     * ایجاد گارانتی جدید
     */
    public function create_item($request) {
        global $wpdb;

        $data = array(
            'product_id' => $request->get_param('product_id'),
            'user_id' => get_current_user_id(),
            'serial_number' => $request->get_param('serial_number'),
            'purchase_date' => $request->get_param('purchase_date'),
            'status' => 'در انتظار بررسی',
            'created_at' => current_time('mysql')
        );

        $result = $wpdb->insert(
            $wpdb->prefix . 'asg_guarantee_requests',
            $data,
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_Error(
                'warranty_creation_failed',
                'ثبت گارانتی با خطا مواجه شد.',
                array('status' => 500)
            );
        }

        $warranty_id = $wpdb->insert_id;
        
        // ارسال نوتیفیکیشن
        do_action('asg_warranty_created', $warranty_id, $data);

        return new WP_REST_Response(
            array(
                'id' => $warranty_id,
                'message' => 'گارانتی با موفقیت ثبت شد.'
            ),
            201
        );
    }

    /**
     * دریافت یک گارانتی خاص
     */
    public function get_item($request) {
        global $wpdb;

        $warranty_id = $request['id'];

        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}asg_guarantee_requests WHERE id = %d",
                $warranty_id
            )
        );

        if (is_null($item)) {
            return new WP_Error(
                'warranty_not_found',
                'گارانتی مورد نظر یافت نشد.',
                array('status' => 404)
            );
        }

        return new WP_REST_Response($item, 200);
    }

    /**
     * بروزرسانی یک گارانتی
     */
    public function update_item($request) {
        global $wpdb;

        $warranty_id = $request['id'];
        
        $data = array();
        $format = array();

        // بروزرسانی فیلدهای ارسال شده
        if ($request->get_param('status')) {
            $data['status'] = $request->get_param('status');
            $format[] = '%s';
        }

        if ($request->get_param('notes')) {
            $data['notes'] = $request->get_param('notes');
            $format[] = '%s';
        }

        if (empty($data)) {
            return new WP_Error(
                'no_data_to_update',
                'هیچ داده‌ای برای بروزرسانی ارسال نشده است.',
                array('status' => 400)
            );
        }

        $data['updated_at'] = current_time('mysql');
        $format[] = '%s';

        $result = $wpdb->update(
            $wpdb->prefix . 'asg_guarantee_requests',
            $data,
            array('id' => $warranty_id),
            $format,
            array('%d')
        );

        if ($result === false) {
            return new WP_Error(
                'warranty_update_failed',
                'بروزرسانی گارانتی با خطا مواجه شد.',
                array('status' => 500)
            );
        }

        // ارسال نوتیفیکیشن
        do_action('asg_warranty_updated', $warranty_id, $data);

        return new WP_REST_Response(
            array(
                'message' => 'گارانتی با موفقیت بروزرسانی شد.'
            ),
            200
        );
    }

    /**
     * حذف یک گارانتی
     */
    public function delete_item($request) {
        global $wpdb;

        $warranty_id = $request['id'];

        $result = $wpdb->delete(
            $wpdb->prefix . 'asg_guarantee_requests',
            array('id' => $warranty_id),
            array('%d')
        );

        if ($result === false) {
            return new WP_Error(
                'warranty_deletion_failed',
                'حذف گارانتی با خطا مواجه شد.',
                array('status' => 500)
            );
        }

        // ارسال نوتیفیکیشن
        do_action('asg_warranty_deleted', $warranty_id);

        return new WP_REST_Response(
            array(
                'message' => 'گارانتی با موفقیت حذف شد.'
            ),
            200
        );
    }

    /**
     * پردازش درخواست Ajax برای ثبت گارانتی
     */
    public function handle_submit_warranty() {
        check_ajax_referer('asg-warranty-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('شما اجازه دسترسی به این عملیات را ندارید.');
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $serial_number = isset($_POST['serial_number']) ? sanitize_text_field($_POST['serial_number']) : '';
        $purchase_date = isset($_POST['purchase_date']) ? sanitize_text_field($_POST['purchase_date']) : '';

        if (!$product_id || !$serial_number || !$purchase_date) {
            wp_send_json_error('لطفا تمام فیلدهای ضروری را پر کنید.');
        }

        $request = new WP_REST_Request('POST', "/warranty/v1/guarantees");
        $request->set_param('product_id', $product_id);
        $request->set_param('serial_number', $serial_number);
        $request->set_param('purchase_date', $purchase_date);

        $response = $this->create_item($request);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        wp_send_json_success($response->get_data());
    }

    /**
     * دریافت اطلاعات محصول
     */
    public function get_product_info() {
        check_ajax_referer('asg-warranty-nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error('شناسه محصول نامعتبر است.');
        }

        $product = wc_get_product($product_id);

        if (!$product) {
            wp_send_json_error('محصول مورد نظر یافت نشد.');
        }

        wp_send_json_success(array(
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'price' => $product->get_price(),
            'warranty_duration' => get_post_meta($product_id, '_warranty_duration', true),
            'image' => get_the_post_thumbnail_url($product_id, 'thumbnail')
        ));
    }

    /**
     * بروزرسانی وضعیت گارانتی
     */
    public function update_warranty_status() {
        check_ajax_referer('asg-warranty-nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('شما اجازه دسترسی به این عملیات را ندارید.');
        }

        $warranty_id = isset($_POST['warranty_id']) ? intval($_POST['warranty_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        if (!$warranty_id || !$status) {
            wp_send_json_error('پارامترهای ضروری ارسال نشده‌اند.');
        }

        $request = new WP_REST_Request('PUT', "/warranty/v1/guarantees/{$warranty_id}");
        $request->set_param('status', $status);

        $response = $this->update_item($request);

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        wp_send_json_success($response->get_data());
    }

        /**
     * دریافت پارامترهای مجموعه
     */
    private function get_collection_params() {
        return array(
            'page' => array(
                'description' => 'شماره صفحه از نتایج.',
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
                'sanitize_callback' => 'absint'
            ),
            'per_page' => array(
                'description' => 'تعداد نتایج در هر صفحه.',
                'type' => 'integer',
                'default' => 10,
                'minimum' => 1,
                'maximum' => 100,
                'sanitize_callback' => 'absint'
            ),
            'status' => array(
                'description' => 'فیلتر بر اساس وضعیت گارانتی.',
                'type' => 'string',
                'enum' => array(
                    'در انتظار بررسی',
                    'آماده ارسال',
                    'ارسال شده',
                    'تعویض شده',
                    'خارج از گارانتی'
                )
            ),
            'product_id' => array(
                'description' => 'فیلتر بر اساس شناسه محصول.',
                'type' => 'integer',
                'minimum' => 1,
                'sanitize_callback' => 'absint'
            ),
            'search' => array(
                'description' => 'جستجو در شماره سریال و توضیحات.',
                'type' => 'string'
            ),
            'orderby' => array(
                'description' => 'مرتب‌سازی بر اساس فیلد.',
                'type' => 'string',
                'enum' => array(
                    'id',
                    'created_at',
                    'updated_at',
                    'status'
                ),
                'default' => 'created_at'
            ),
            'order' => array(
                'description' => 'ترتیب مرتب‌سازی.',
                'type' => 'string',
                'enum' => array(
                    'asc',
                    'desc'
                ),
                'default' => 'desc'
            )
        );
    }

    /**
     * دریافت آرگومان‌های نقطه پایانی برای طرح آیتم
     */
    private function get_endpoint_args_for_item_schema($method = WP_REST_Server::CREATABLE) {
        $args = array();

        switch ($method) {
            case WP_REST_Server::CREATABLE:
                $args = array(
                    'product_id' => array(
                        'required' => true,
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' => 'شناسه محصول',
                        'validate_callback' => function($param) {
                            return is_numeric($param) && $param > 0;
                        }
                    ),
                    'serial_number' => array(
                        'required' => true,
                        'type' => 'string',
                        'description' => 'شماره سریال محصول',
                        'validate_callback' => function($param) {
                            return !empty($param) && is_string($param);
                        }
                    ),
                    'purchase_date' => array(
                        'required' => true,
                        'type' => 'string',
                        'description' => 'تاریخ خرید',
                        'validate_callback' => function($param) {
                            return !empty($param) && strtotime($param) !== false;
                        }
                    )
                );
                break;

            case WP_REST_Server::EDITABLE:
                $args = array(
                    'status' => array(
                        'type' => 'string',
                        'enum' => array(
                            'در انتظار بررسی',
                            'آماده ارسال',
                            'ارسال شده',
                            'تعویض شده',
                            'خارج از گارانتی'
                        ),
                        'description' => 'وضعیت گارانتی'
                    ),
                    'notes' => array(
                        'type' => 'string',
                        'description' => 'یادداشت‌های گارانتی'
                    )
                );
                break;
        }

        return $args;
    }
}