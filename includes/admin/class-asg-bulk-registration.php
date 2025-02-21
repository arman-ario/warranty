<?php
/**
 * کلاس مدیریت ثبت گارانتی دسته‌ای
 */
class ASG_Bulk_Registration {

    /**
     * نمایش صفحه ثبت گارانتی دسته‌ای
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('شما اجازه دسترسی به این صفحه را ندارید.');
        }

        // پردازش فایل آپلود شده
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_bulk_guarantee'])) {
            self::process_bulk_upload();
        }

        echo '<div class="wrap">';
        echo '<h1>ثبت گارانتی دسته‌ای</h1>';
        
        // نمایش فرم آپلود
        self::render_upload_form();
        
        // نمایش نمونه فایل اکسل
        self::render_sample_file();

        echo '</div>';
    }

    /**
     * پردازش فایل آپلود شده
     */
    private static function process_bulk_upload() {
        // بررسی امنیتی
        check_admin_referer('asg_bulk_guarantee', 'asg_nonce');

        if (!isset($_FILES['bulk_file']) || $_FILES['bulk_file']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>خطا در آپلود فایل. لطفاً دوباره تلاش کنید.</p></div>';
            return;
        }

        // بررسی نوع فایل
        $file_type = wp_check_filetype($_FILES['bulk_file']['name']);
        if (!in_array($file_type['ext'], ['xlsx', 'xls', 'csv'])) {
            echo '<div class="notice notice-error"><p>فرمت فایل باید XLSX، XLS یا CSV باشد.</p></div>';
            return;
        }

        // خواندن فایل اکسل
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
        
        require_once ASG_PLUGIN_PATH . 'vendor/autoload.php';
        
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['bulk_file']['tmp_name']);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            // حذف ردیف هدر
            array_shift($rows);
            
            $results = self::process_rows($rows);
            
            // نمایش نتایج
            self::display_results($results);

        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>خطا در پردازش فایل: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    /**
     * پردازش ردیف‌های فایل اکسل
     */
    private static function process_rows($rows) {
        global $wpdb;
        
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];

        foreach ($rows as $row_index => $row) {
            try {
                // اعتبارسنجی داده‌ها
                if (count($row) < 7) {
                    throw new Exception('تعداد ستون‌های نامعتبر');
                }

                list($product_sku, $user_email, $tamin_email, $defect_desc, 
                     $receipt_date, $status, $image_url) = $row;

                // بررسی محصول
                $product_id = wc_get_product_id_by_sku($product_sku);
                if (!$product_id) {
                    throw new Exception('محصول یافت نشد: ' . $product_sku);
                }

                // بررسی کاربر
                $user = get_user_by('email', $user_email);
                if (!$user) {
                    throw new Exception('کاربر یافت نشد: ' . $user_email);
                }

                // بررسی تامین کننده
                $tamin_user = get_user_by('email', $tamin_email);
                if (!$tamin_user || !in_array('tamin', $tamin_user->roles)) {
                    throw new Exception('تامین کننده نامعتبر: ' . $tamin_email);
                }

                // پردازش تاریخ
                $date_parts = explode('/', $receipt_date);
                if (count($date_parts) !== 3) {
                    throw new Exception('فرمت تاریخ نامعتبر: ' . $receipt_date);
                }

                // دانلود و ذخیره تصویر
                $image_id = 0;
                if (!empty($image_url)) {
                    $image_id = self::process_image($image_url);
                }

                // درج در دیتابیس
                $result = $wpdb->insert(
                    $wpdb->prefix . 'asg_guarantee_requests',
                    [
                        'product_id' => $product_id,
                        'user_id' => $user->ID,
                        'tamin_user_id' => $tamin_user->ID,
                        'defect_description' => $defect_desc,
                        'status' => $status,
                        'receipt_day' => intval($date_parts[2]),
                        'receipt_month' => self::convert_month($date_parts[1]),
                        'receipt_year' => intval($date_parts[0]),
                        'image_id' => $image_id,
                        'created_at' => current_time('mysql')
                    ],
                    ['%d', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s']
                );

                if ($result) {
                    $results['success']++;
                    do_action('asg_warranty_registered', $wpdb->insert_id);
                } else {
                    throw new Exception('خطا در ثبت در دیتابیس');
                }

            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "ردیف " . ($row_index + 2) . ": " . $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * پردازش و ذخیره تصویر از URL
     */
    private static function process_image($url) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // دانلود تصویر
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            throw new Exception('خطا در دانلود تصویر: ' . $tmp->get_error_message());
        }

        $file_array = [
            'name' => basename($url),
            'tmp_name' => $tmp
        ];

        // افزودن به کتابخانه رسانه
        $image_id = media_handle_sideload($file_array, 0);
        if (is_wp_error($image_id)) {
            @unlink($tmp);
            throw new Exception('خطا در ذخیره تصویر: ' . $image_id->get_error_message());
        }

        return $image_id;
    }

    /**
     * تبدیل شماره ماه به نام فارسی
     */
    private static function convert_month($month_number) {
        $months = [
            '1' => 'فروردین',
            '2' => 'اردیبهشت',
            '3' => 'خرداد',
            '4' => 'تیر',
            '5' => 'مرداد',
            '6' => 'شهریور',
            '7' => 'مهر',
            '8' => 'آبان',
            '9' => 'آذر',
            '10' => 'دی',
            '11' => 'بهمن',
            '12' => 'اسفند'
        ];

        return isset($months[$month_number]) ? $months[$month_number] : '';
    }

    /**
     * نمایش نتایج پردازش فایل
     */
    private static function display_results($results) {
        echo '<div class="notice notice-info">';
        echo '<p>تعداد موارد موفق: ' . $results['success'] . '</p>';
        echo '<p>تعداد موارد ناموفق: ' . $results['failed'] . '</p>';
        
        if (!empty($results['errors'])) {
            echo '<div class="asg-error-list">';
            echo '<h4>خطاها:</h4>';
            echo '<ul>';
            foreach ($results['errors'] as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        
        echo '</div>';
    }

    /**
     * نمایش فرم آپلود
     */
    private static function render_upload_form() {
        ?>
        <div class="asg-upload-section">
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('asg_bulk_guarantee', 'asg_nonce'); ?>
                
                <div class="asg-form-group">
                    <label for="bulk_file">انتخاب فایل اکسل:</label>
                    <input type="file" name="bulk_file" id="bulk_file" accept=".xlsx,.xls,.csv" required>
                    <p class="description">فرمت‌های مجاز: XLSX، XLS، CSV</p>
                </div>

                <div class="asg-form-group">
                    <input type="submit" name="submit_bulk_guarantee" class="button button-primary" 
                           value="آپلود و پردازش">
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * نمایش بخش نمونه فایل
     */
    private static function render_sample_file() {
        ?>
        <div class="asg-sample-section">
            <h3>نمونه ساختار فایل</h3>
            <p>فایل اکسل باید شامل ستون‌های زیر باشد:</p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>کد محصول</th>
                        <th>ایمیل مشتری</th>
                        <th>ایمیل تامین کننده</th>
                        <th>شرح نقص</th>
                        <th>تاریخ دریافت</th>
                        <th>وضعیت</th>
                        <th>لینک تصویر</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>PRD-001</td>
                        <td>customer@example.com</td>
                        <td>supplier@example.com</td>
                        <td>توضیحات نقص محصول</td>
                        <td>1403/01/01</td>
                        <td>آماده ارسال</td>
                        <td>https://example.com/image.jpg</td>
                    </tr>
                </tbody>
            </table>
            <p class="description">
                نکته: تمام ستون‌ها اجباری هستند به جز لینک تصویر.<br>
                تاریخ باید به فرمت YYYY/MM/DD وارد شود.
            </p>
            <a href="<?php echo esc_url(admin_url('admin-post.php?action=asg_download_sample')); ?>" 
               class="button">
                دانلود فایل نمونه
            </a>
        </div>
        <?php
    }
}