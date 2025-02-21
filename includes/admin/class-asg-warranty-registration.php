<?php
/**
 * کلاس مدیریت ثبت گارانتی
 */
class ASG_Warranty_Registration {

    /**
     * نمایش صفحه اصلی مدیریت گارانتی
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('شما اجازه دسترسی به این صفحه را ندارید.');
        }

        echo '<div class="wrap">';
        echo '<h1>مدیریت گارانتی</h1>';
        echo '<a href="' . admin_url('admin.php?page=warranty-management-add') . '" class="button button-primary">ثبت گارانتی جدید</a>';
        echo '<h2>لیست درخواست‌های گارانتی</h2>';
        self::show_requests_table();
        echo '</div>';
    }

    /**
     * نمایش صفحه ثبت گارانتی جدید
     */
    public static function render_add_page() {
        global $wpdb;

        if (!current_user_can('manage_options')) {
            wp_die('شما اجازه دسترسی به این صفحه را ندارید.');
        }

        // پردازش فرم در صورت ارسال
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_guarantee_request'])) {
            self::handle_form_submission();
        }

        // نمایش فرم
        echo '<div class="wrap">';
        echo '<h1>ثبت گارانتی جدید</h1>';
        self::render_add_form();
        echo '</div>';

        // اضافه کردن اسکریپت‌ها
        self::enqueue_scripts();
    }

    /**
     * پردازش فرم ارسالی
     */
    private static function handle_form_submission() {
        global $wpdb;

        // بررسی امنیتی
        check_admin_referer('asg_add_guarantee', 'asg_nonce');

        // دریافت و پاکسازی داده‌ها
        $data = array(
            'product_id' => intval($_POST['product_id']),
            'user_id' => intval($_POST['user_id']),
            'tamin_user_id' => intval($_POST['tamin_user_id']),
            'defect_description' => sanitize_textarea_field($_POST['defect_description']),
            'expert_comment' => sanitize_textarea_field($_POST['expert_comment']),
            'status' => sanitize_text_field($_POST['status']),
            'receipt_day' => intval($_POST['receipt_day']),
            'receipt_month' => sanitize_text_field($_POST['receipt_month']),
            'receipt_year' => intval($_POST['receipt_year']),
            'image_id' => intval($_POST['image_id']),
            'created_at' => current_time('mysql')
        );

        // درج در دیتابیس
        $result = $wpdb->insert(
            $wpdb->prefix . 'asg_guarantee_requests',
            $data,
            array('%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s')
        );

        if ($result) {
            // ثبت در گزارش‌ها
            do_action('asg_warranty_registered', $wpdb->insert_id, $data);
            
            echo '<div class="notice notice-success"><p>درخواست گارانتی با موفقیت ثبت شد.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>خطا در ثبت درخواست: ' . esc_html($wpdb->last_error) . '</p></div>';
        }
    }

    /**
     * نمایش فرم ثبت گارانتی
     */
    private static function render_add_form() {
        wp_nonce_field('asg_add_guarantee', 'asg_nonce');
        ?>
        <form method="post" action="" class="asg-form-container">
            <div class="asg-row">
                <!-- بخش انتخاب محصول -->
                <div class="asg-col">
                    <div class="asg-card">
                        <div class="asg-card-header">انتخاب محصول</div>
                        <div class="asg-card-body">
                            <div class="asg-form-group">
                                <label for="product_id">محصول:</label>
                                <select name="product_id" id="product_id" class="asg-select2" required>
                                    <option value="">جستجو و انتخاب محصول...</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- بخش انتخاب مشتری -->
                <div class="asg-col">
                    <div class="asg-card">
                        <div class="asg-card-header">مشتری</div>
                        <div class="asg-card-body">
                            <div class="asg-form-group">
                                <label for="user_id">مشتری:</label>
                                <select name="user_id" id="user_id" class="asg-select2" required>
                                    <option value="">جستجوی مشتری...</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- بخش تامین کننده -->
                <div class="asg-col">
                    <div class="asg-card">
                        <div class="asg-card-header">تامین کننده</div>
                        <div class="asg-card-body">
                            <div class="asg-form-group">
                                <label for="tamin_user_id">تامین کننده:</label>
                                <select name="tamin_user_id" id="tamin_user_id" class="asg-select2">
                                    <?php
                                    $tamin_users = get_users(array('role' => 'tamin'));
                                    foreach ($tamin_users as $user) {
                                        echo '<option value="' . esc_attr($user->ID) . '">' . 
                                             esc_html($user->display_name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- بخش تاریخ -->
            <div class="asg-row">
                <div class="asg-col">
                    <div class="asg-card">
                        <div class="asg-card-header">تاریخ دریافت (شمسی)</div>
                        <div class="asg-card-body">
                            <div class="asg-date-fields">
                                <div class="asg-form-group">
                                    <label for="receipt_day">روز:</label>
                                    <select name="receipt_day" id="receipt_day" class="asg-date-select" required>
                                        <?php for ($i = 1; $i <= 31; $i++) echo "<option value='$i'>$i</option>"; ?>
                                    </select>
                                </div>
                                <div class="asg-form-group">
                                    <label for="receipt_month">ماه:</label>
                                    <select name="receipt_month" id="receipt_month" class="asg-date-select" required>
                                        <?php
                                        $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 
                                                 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
                                        foreach ($months as $month) {
                                            echo "<option value='$month'>$month</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="asg-form-group">
                                    <label for="receipt_year">سال:</label>
                                    <select name="receipt_year" id="receipt_year" class="asg-date-select" required>
                                        <?php for ($year = 1403; $year <= 1410; $year++) echo "<option value='$year'>$year</option>"; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- بخش اطلاعات فنی -->
            <div class="asg-row">
                <div class="asg-col">
                    <div class="asg-card">
                        <div class="asg-card-header">مشخصات فنی</div>
                        <div class="asg-card-body">
                            <div class="asg-form-group">
                                <label for="defect_description">شرح کامل نقص:</label>
                                <textarea name="defect_description" id="defect_description" rows="5" required 
                                          class="asg-textarea"></textarea>
                            </div>
                            <div class="asg-form-group">
                                <label for="expert_comment">نظر کارشناسی:</label>
                                <textarea name="expert_comment" id="expert_comment" rows="5" 
                                          class="asg-textarea"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- بخش وضعیت و مستندات -->
                <div class="asg-col">
                    <div class="asg-card">
                        <div class="asg-card-header">وضعیت و مستندات</div>
                        <div class="asg-card-body">
                            <div class="asg-form-group">
                                <label for="status">وضعیت فعلی:</label>
                                <select name="status" id="status" class="asg-select2" required>
                                    <?php
                                    $statuses = get_option('asg_statuses', array(
                                        'آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'
                                    ));
                                    foreach ($statuses as $status) {
                                        echo "<option value='$status'>$status</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="asg-form-group">
                                <label>مستندات تصویری:</label>
                                <div class="asg-upload-wrapper">
                                    <input type="hidden" name="image_id" id="image_id">
                                    <button type="button" class="button button-secondary" id="asg-upload-btn">
                                        انتخاب تصویر
                                    </button>
                                    <div id="asg-image-preview" class="asg-image-preview"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- دکمه ثبت -->
            <div class="asg-submit-wrapper">
                <input type="submit" name="submit_guarantee_request" value="ثبت نهایی درخواست" 
                       class="button button-primary button-large">
            </div>
        </form>
        <?php
    }

    /**
     * نمایش جدول درخواست‌ها
     */
    private static function show_requests_table() {
        global $wpdb;
        
        // تنظیمات صفحه‌بندی
        $per_page = 10;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // شرایط فیلتر
        $where = array();
        $values = array();

        if (!empty($_GET['filter_id'])) {
            $where[] = 'id = %d';
            $values[] = intval($_GET['filter_id']);
        }
        if (!empty($_GET['filter_product'])) {
            $where[] = 'product_id = %d';
            $values[] = intval($_GET['filter_product']);
        }
        if (!empty($_GET['filter_status'])) {
            $where[] = 'status = %s';
            $values[] = sanitize_text_field($_GET['filter_status']);
        }

        $where_clause = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        // دریافت تعداد کل رکوردها
        $total_items = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}asg_guarantee_requests" . $where_clause,
                $values
            )
        );

        // دریافت درخواست‌ها
        $requests = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}asg_guarantee_requests" . 
                $where_clause . 
                " ORDER BY id DESC LIMIT %d OFFSET %d",
                array_merge($values, array($per_page, $offset))
            )
        );

        // نمایش فرم فیلتر
        self::render_filter_form();

        // نمایش جدول
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>
            <tr>
                <th width="60">شماره</th>
                <th width="150">محصول</th>
                <th width="120">مشتری</th>
                <th width="100">وضعیت</th>
                <th width="120">تاریخ دریافت</th>
                <th width="60">عکس</th>
                <th>یادداشت‌ها</th>
                <th width="80">عملیات</th>
            </tr>
        </thead>';
        
        echo '<tbody>';
        if ($requests) {
            foreach ($requests as $request) {
                self::render_request_row($request);
            }
        } else {
            echo '<tr><td colspan="8">موردی یافت نشد.</td></tr>';
        }
        echo '</tbody>';
        echo '</table>';

        // نمایش صفحه‌بندی
        $total_pages = ceil($total_items / $per_page);
        self::render_pagination($total_pages, $current_page);
    }

   /**
     * نمایش فرم فیلتر
     */
    private static function render_filter_form() {
        ?>
        <form method="get" action="">
            <input type="hidden" name="page" value="warranty-management">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <!-- فیلتر شناسه -->
                    <input type="number" name="filter_id" placeholder="شماره" 
                           value="<?php echo isset($_GET['filter_id']) ? esc_attr($_GET['filter_id']) : ''; ?>" 
                           style="width: 80px;">
                    
                    <!-- فیلتر محصول -->
                    <select name="filter_product" style="width: 200px;">
                        <option value="">همه محصولات</option>
                        <?php
                        if (isset($_GET['filter_product'])) {
                            $product = wc_get_product(intval($_GET['filter_product']));
                            if ($product) {
                                echo '<option value="' . esc_attr($product->get_id()) . '" selected>' . 
                                     esc_html($product->get_name()) . '</option>';
                            }
                        }
                        ?>
                    </select>
                    
                    <!-- فیلتر وضعیت -->
                    <select name="filter_status" style="width: 120px;">
                        <option value="">همه وضعیت‌ها</option>
                        <?php
                        $statuses = get_option('asg_statuses', array(
                            'آماده ارسال', 'ارسال شده', 'تعویض شده', 'خارج از گارانتی'
                        ));
                        foreach ($statuses as $status) {
                            $selected = (isset($_GET['filter_status']) && $_GET['filter_status'] === $status) ? 'selected' : '';
                            echo '<option value="' . esc_attr($status) . '" ' . $selected . '>' . 
                                 esc_html($status) . '</option>';
                        }
                        ?>
                    </select>
                    
                    <!-- دکمه‌های عملیات -->
                    <input type="submit" value="اعمال فیلتر" class="button">
                    <a href="<?php echo admin_url('admin.php?page=warranty-management'); ?>" class="button">
                        حذف فیلترها
                    </a>
                </div>
            </div>
        </form>
        <?php
    }

    /**
     * نمایش یک ردیف از جدول درخواست‌ها
     */
    private static function render_request_row($request) {
        echo '<tr>';
        echo '<td>' . esc_html($request->id) . '</td>';
        echo '<td>' . esc_html(get_the_title($request->product_id)) . '</td>';
        echo '<td>' . esc_html(get_userdata($request->user_id)->display_name) . '</td>';
        
        // نمایش وضعیت با کلاس مناسب
        echo '<td>';
        self::render_status_badge($request->status);
        echo '</td>';
        
        // نمایش تاریخ
        echo '<td>' . esc_html($request->receipt_day . ' ' . 
             $request->receipt_month . ' ' . 
             $request->receipt_year) . '</td>';
        
        // نمایش وضعیت تصویر
        echo '<td style="text-align: center;">';
        if ($request->image_id) {
            echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="دارای تصویر"></span>';
        } else {
            echo '<span class="dashicons dashicons-no-alt" style="color: #dc3232;" title="بدون تصویر"></span>';
        }
        echo '</td>';
        
        // نمایش یادداشت‌ها
        echo '<td>';
        self::render_request_notes($request->id);
        echo '</td>';
        
        // دکمه‌های عملیات
        echo '<td>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=warranty-management-edit&id=' . $request->id)) . '" ' .
             'class="button button-small">ویرایش</a>';
        echo '</td>';
        echo '</tr>';
    }

    /**
     * نمایش نشان وضعیت
     */
    private static function render_status_badge($status) {
        $classes = array(
            'آماده ارسال' => 'status-pending',
            'ارسال شده' => 'status-sent',
            'تعویض شده' => 'status-replaced',
            'خارج از گارانتی' => 'status-expired'
        );

        $class = isset($classes[$status]) ? $classes[$status] : '';
        echo '<span class="asg-status-badge ' . esc_attr($class) . '">' . esc_html($status) . '</span>';
    }

    /**
     * نمایش یادداشت‌های درخواست
     */
    private static function render_request_notes($request_id) {
        global $wpdb;
        
        $notes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}asg_guarantee_notes 
            WHERE request_id = %d 
            ORDER BY created_at DESC 
            LIMIT 2",
            $request_id
        ));

        if ($notes) {
            foreach ($notes as $note) {
                echo '<div class="asg-note">';
                echo '<small class="asg-note-date">' . 
                     date_i18n('Y/m/d H:i', strtotime($note->created_at)) . '</small>';
                echo '<p class="asg-note-content">' . 
                     wp_trim_words(esc_html($note->note), 10, '...') . '</p>';
                echo '</div>';
            }
        } else {
            echo '-';
        }
    }

    /**
     * نمایش صفحه‌بندی
     */
    private static function render_pagination($total_pages, $current_page) {
        if ($total_pages > 1) {
            echo '<div class="tablenav bottom">';
            echo '<div class="tablenav-pages">';
            echo paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => __('&laquo;'),
                'next_text' => __('&raquo;'),
                'total' => $total_pages,
                'current' => $current_page,
                'add_args' => array_filter(array(
                    'filter_id' => isset($_GET['filter_id']) ? $_GET['filter_id'] : '',
                    'filter_product' => isset($_GET['filter_product']) ? $_GET['filter_product'] : '',
                    'filter_status' => isset($_GET['filter_status']) ? $_GET['filter_status'] : ''
                ))
            ));
            echo '</div>';
            echo '</div>';
        }
    }

    /**
     * اضافه کردن اسکریپت‌ها و استایل‌های مورد نیاز
     */
    private static function enqueue_scripts() {
        wp_enqueue_media();
        wp_enqueue_script('select2');
        wp_enqueue_style('select2');

        // اسکریپت‌های سفارشی
        wp_add_inline_script('jquery', self::get_custom_scripts());
    }

    /**
     * دریافت اسکریپت‌های سفارشی
     */
    private static function get_custom_scripts() {
        ob_start();
        ?>
        jQuery(document).ready(function($) {
            // تنظیمات Select2 برای محصولات
            $('#product_id').select2({
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'asg_search_products',
                            search: params.term,
                            page: params.page || 1
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data,
                            pagination: {
                                more: (params.page * 30) < data.total_count
                            }
                        };
                    },
                    cache: true
                },
                minimumInputLength: 3,
                placeholder: 'جستجوی محصول...',
                language: {
                    noResults: function() {
                        return 'محصولی یافت نشد';
                    },
                    searching: function() {
                        return 'در حال جستجو...';
                    }
                }
            });

            // تنظیمات Select2 برای کاربران
            $('#user_id').select2({
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'asg_search_users',
                            search: params.term,
                            page: params.page || 1
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data,
                            pagination: {
                                more: (params.page * 30) < data.total_count
                            }
                        };
                    },
                    cache: true
                },
                minimumInputLength: 3,
                placeholder: 'جستجوی مشتری...',
                language: {
                    noResults: function() {
                        return 'مشتری یافت نشد';
                    },
                    searching: function() {
                        return 'در حال جستجو...';
                    }
                }
            });

            // مدیریت آپلود تصویر
            $('#asg-upload-btn').click(function(e) {
                e.preventDefault();
                var frame = wp.media({
                    title: 'انتخاب تصویر گارانتی',
                    button: { text: 'انتخاب تصویر' },
                    multiple: false,
                    library: { type: 'image' }
                });

                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#image_id').val(attachment.id);
                    $('#asg-image-preview').html(
                        '<div class="asg-preview-item">' +
                            '<img src="' + attachment.sizes.thumbnail.url + '" alt="پیش‌نمایش">' +
                            '<button type="button" class="button button-small asg-remove-image">حذف</button>' +
                        '</div>'
                    );
                });

                frame.open();
            });

            // حذف تصویر
            $(document).on('click', '.asg-remove-image', function() {
                $('#image_id').val('');
                $('#asg-image-preview').html('');
            });

            // اعتبارسنجی فرم
            $('form.asg-form-container').on('submit', function(e) {
                var isValid = true;
                var firstError = null;

                $(this).find('[required]').each(function() {
                    if (!$(this).val()) {
                        isValid = false;
                        $(this).addClass('error');
                        if (!firstError) firstError = $(this);
                    } else {
                        $(this).removeClass('error');
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    firstError.focus();
                    alert('لطفاً تمام فیلدهای اجباری را پر کنید.');
                    return false;
                }
            });
        });
        <?php
        return ob_get_clean();
    }
}