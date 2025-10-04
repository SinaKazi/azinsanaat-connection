<?php
/**
 * Plugin Name: Azinsanaat Connection
 * Description: اتصال به آذین صنعت و همگام‌سازی محصولات از طریق API ووکامرس.
 * Version:     1.2.0
 * Author:      Sina Kazemi
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Azinsanaat_Connection')) {
    class Azinsanaat_Connection
    {
        const OPTION_KEY = 'azinsanaat_connection_options';
        const NONCE_ACTION_TEST = 'azinsanaat_connection_test_connection';
        const NONCE_ACTION_IMPORT = 'azinsanaat_connection_import_product';
        const CRON_HOOK = 'azinsanaat_connection_sync_products';
        const META_REMOTE_ID = '_azinsanaat_remote_id';
        const META_LAST_SYNC = '_azinsanaat_last_synced';

        /**
         * Bootstraps plugin hooks.
         */
        public static function init(): void
        {
            add_filter('cron_schedules', [__CLASS__, 'register_cron_schedules']);
            add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled_sync']);
            add_action('init', [__CLASS__, 'ensure_cron_schedule']);
            add_action('update_option_' . self::OPTION_KEY, [__CLASS__, 'handle_options_updated'], 10, 3);

            if (!is_admin()) {
                return;
            }

            add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
            add_action('admin_init', [__CLASS__, 'register_settings']);
            add_action('admin_post_azinsanaat_test_connection', [__CLASS__, 'handle_test_connection']);
            add_action('admin_post_azinsanaat_import_product', [__CLASS__, 'handle_import_product']);
            add_action('add_meta_boxes_product', [__CLASS__, 'register_product_meta_box']);
            add_action('save_post_product', [__CLASS__, 'handle_save_product']);
            add_action('admin_notices', [__CLASS__, 'display_product_sync_notice']);
        }

        /**
         * Registers plugin options.
         */
        public static function register_settings(): void
        {
            register_setting(
                'azinsanaat_connection_options_group',
                self::OPTION_KEY,
                [
                    'type'              => 'array',
                    'sanitize_callback' => [__CLASS__, 'sanitize_options'],
                    'default'           => [
                        'store_url'      => 'https://azinsanaat.com',
                        'consumer_key'   => '',
                        'consumer_secret'=> '',
                        'sync_interval'  => '15min',
                    ],
                ]
            );
        }

        /**
         * Defines admin pages.
         */
        public static function register_admin_pages(): void
        {
            add_menu_page(
                __('Azinsanaat Connection', 'azinsanaat-connection'),
                __('اتصال آذین صنعت', 'azinsanaat-connection'),
                'manage_options',
                'azinsanaat-connection',
                [__CLASS__, 'render_settings_page'],
                'dashicons-rest-api'
            );

            add_submenu_page(
                'azinsanaat-connection',
                __('تنظیمات اتصال', 'azinsanaat-connection'),
                __('تنظیمات', 'azinsanaat-connection'),
                'manage_options',
                'azinsanaat-connection',
                [__CLASS__, 'render_settings_page']
            );

            add_submenu_page(
                'azinsanaat-connection',
                __('محصولات وب‌سرویس', 'azinsanaat-connection'),
                __('محصولات', 'azinsanaat-connection'),
                'manage_options',
                'azinsanaat-connection-products',
                [__CLASS__, 'render_products_page']
            );
        }

        /**
         * Sanitizes options before persisting.
         */
        public static function sanitize_options($input): array
        {
            $output = [];
            $output['store_url'] = isset($input['store_url']) ? esc_url_raw(trim($input['store_url'])) : '';
            $output['consumer_key'] = isset($input['consumer_key']) ? sanitize_text_field($input['consumer_key']) : '';
            $output['consumer_secret'] = isset($input['consumer_secret']) ? sanitize_text_field($input['consumer_secret']) : '';
            $output['sync_interval'] = isset($input['sync_interval']) ? self::sanitize_sync_interval($input['sync_interval']) : '15min';

            return $output;
        }

        protected static function sanitize_sync_interval($value): string
        {
            $value = is_string($value) ? sanitize_text_field($value) : '';
            $intervals = self::get_sync_intervals();

            if (!isset($intervals[$value])) {
                $value = '15min';
            }

            return $value;
        }

        protected static function get_sync_intervals(): array
        {
            return [
                '15min' => [
                    'interval' => 15 * MINUTE_IN_SECONDS,
                    'label'    => __('هر ۱۵ دقیقه یک‌بار', 'azinsanaat-connection'),
                ],
                '30min' => [
                    'interval' => 30 * MINUTE_IN_SECONDS,
                    'label'    => __('هر ۳۰ دقیقه یک‌بار', 'azinsanaat-connection'),
                ],
                '1hour' => [
                    'interval' => HOUR_IN_SECONDS,
                    'label'    => __('هر ۱ ساعت یک‌بار', 'azinsanaat-connection'),
                ],
                '3hour' => [
                    'interval' => 3 * HOUR_IN_SECONDS,
                    'label'    => __('هر ۳ ساعت یک‌بار', 'azinsanaat-connection'),
                ],
            ];
        }

        /**
         * Outputs the settings page content.
         */
        public static function render_settings_page(): void
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            $options = get_option(self::OPTION_KEY);
            $connection_message = self::get_transient_message('azinsanaat_connection_status_message');
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('تنظیمات اتصال آذین صنعت', 'azinsanaat-connection'); ?></h1>
                <?php if ($connection_message) : ?>
                    <div class="notice notice-<?php echo esc_attr($connection_message['type']); ?>">
                        <p><?php echo esc_html($connection_message['message']); ?></p>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                    <?php
                    settings_fields('azinsanaat_connection_options_group');
                    ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row"><label for="azinsanaat-store-url"><?php esc_html_e('آدرس فروشگاه', 'azinsanaat-connection'); ?></label></th>
                            <td>
                                <input id="azinsanaat-store-url" type="url" name="<?php echo esc_attr(self::OPTION_KEY); ?>[store_url]" value="<?php echo esc_attr($options['store_url'] ?? ''); ?>" class="regular-text" required>
                                <p class="description"><?php esc_html_e('مثال: https://azinsanaat.com', 'azinsanaat-connection'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="azinsanaat-consumer-key"><?php esc_html_e('Consumer Key', 'azinsanaat-connection'); ?></label></th>
                            <td>
                                <input id="azinsanaat-consumer-key" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[consumer_key]" value="<?php echo esc_attr($options['consumer_key'] ?? ''); ?>" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="azinsanaat-consumer-secret"><?php esc_html_e('Consumer Secret', 'azinsanaat-connection'); ?></label></th>
                            <td>
                                <input id="azinsanaat-consumer-secret" type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[consumer_secret]" value="<?php echo esc_attr($options['consumer_secret'] ?? ''); ?>" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="azinsanaat-sync-interval"><?php esc_html_e('بازه زمانی همگام‌سازی خودکار', 'azinsanaat-connection'); ?></label></th>
                            <td>
                                <select id="azinsanaat-sync-interval" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_interval]">
                                    <?php
                                    $selected_interval = $options['sync_interval'] ?? '15min';
                                    foreach (self::get_sync_intervals() as $key => $interval) :
                                        ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($selected_interval, $key); ?>><?php echo esc_html($interval['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('زمان‌بندی اجرای خودکار به‌روزرسانی قیمت و موجودی محصولات متصل.', 'azinsanaat-connection'); ?></p>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                    <?php submit_button(__('ذخیره تنظیمات', 'azinsanaat-connection')); ?>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1.5rem;">
                    <?php wp_nonce_field(self::NONCE_ACTION_TEST); ?>
                    <input type="hidden" name="action" value="azinsanaat_test_connection">
                    <?php submit_button(__('بررسی وضعیت اتصال', 'azinsanaat-connection'), 'secondary', 'submit', false); ?>
                </form>
            </div>
            <?php
        }

        /**
         * Handles the connection testing.
         */
        public static function handle_test_connection(): void
        {
            if (!current_user_can('manage_options')) {
                wp_die(__('شما اجازه دسترسی ندارید.', 'azinsanaat-connection'));
            }

            check_admin_referer(self::NONCE_ACTION_TEST);

            $client = self::get_api_client();
            if (is_wp_error($client)) {
                self::set_transient_message('azinsanaat_connection_status_message', [
                    'type'    => 'error',
                    'message' => $client->get_error_message(),
                ]);
                wp_safe_redirect(self::get_settings_page_url());
                exit;
            }

            $response = $client->get('products', ['per_page' => 1]);
            if (is_wp_error($response)) {
                self::set_transient_message('azinsanaat_connection_status_message', [
                    'type'    => 'error',
                    'message' => $response->get_error_message(),
                ]);
            } else {
                $status = wp_remote_retrieve_response_code($response);
                if ($status >= 200 && $status < 300) {
                    self::set_transient_message('azinsanaat_connection_status_message', [
                        'type'    => 'success',
                        'message' => __('اتصال با موفقیت برقرار شد.', 'azinsanaat-connection'),
                    ]);
                } else {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $message = $body['message'] ?? sprintf(__('پاسخ نامعتبر از سرور (کد: %s).', 'azinsanaat-connection'), $status);
                    self::set_transient_message('azinsanaat_connection_status_message', [
                        'type'    => 'error',
                        'message' => $message,
                    ]);
                }
            }

            wp_safe_redirect(self::get_settings_page_url());
            exit;
        }

        /**
         * Renders remote products list.
         */
        public static function render_products_page(): void
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            $client = self::get_api_client();
            $products = [];
            $error_message = '';

            if (is_wp_error($client)) {
                $error_message = $client->get_error_message();
            } else {
                $page = 1;
                $per_page = 100;
                $all_products = [];

                do {
                    $response = $client->get('products', [
                        'per_page' => $per_page,
                        'page'     => $page,
                        'status'   => 'any',
                    ]);

                    if (is_wp_error($response)) {
                        $error_message = $response->get_error_message();
                        break;
                    }

                    $status = wp_remote_retrieve_response_code($response);
                    if ($status < 200 || $status >= 300) {
                        $body = json_decode(wp_remote_retrieve_body($response), true);
                        $error_message = $body['message'] ?? sprintf(__('پاسخ نامعتبر از سرور (کد: %s).', 'azinsanaat-connection'), $status);
                        break;
                    }

                    $batch = json_decode(wp_remote_retrieve_body($response), true);
                    if (!is_array($batch)) {
                        $error_message = __('پاسخ نامعتبر از سرور دریافت شد.', 'azinsanaat-connection');
                        break;
                    }

                    if (!empty($batch)) {
                        $all_products = array_merge($all_products, $batch);
                    }

                    $page++;
                } while (!empty($batch) && count($batch) === $per_page);

                if (!$error_message) {
                    $products = $all_products;
                }
            }

            $notice = self::get_transient_message('azinsanaat_connection_import_notice');
            ?>
            <div class="wrap">
                <h1><?php esc_html_e('محصولات وب‌سرویس آذین صنعت', 'azinsanaat-connection'); ?></h1>
                <?php if ($error_message) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html($error_message); ?></p></div>
                <?php endif; ?>
                <?php if ($notice) : ?>
                    <div class="notice notice-<?php echo esc_attr($notice['type']); ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
                <?php endif; ?>
                <?php if (!$error_message && empty($products)) : ?>
                    <p><?php esc_html_e('هیچ محصولی یافت نشد.', 'azinsanaat-connection'); ?></p>
                <?php elseif (!$error_message) : ?>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th><?php esc_html_e('ردیف', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('شناسه', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('نام', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('قیمت', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('وضعیت موجودی', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('تعداد موجودی', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('عملیات', 'azinsanaat-connection'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $index => $product) : ?>
                            <tr>
                                <td><?php echo esc_html($index + 1); ?></td>
                                <td><?php echo esc_html($product['id']); ?></td>
                                <td><?php echo esc_html($product['name']); ?></td>
                                <td><?php echo isset($product['price']) ? esc_html($product['price']) : '—'; ?></td>
                                <td><?php echo esc_html(self::format_stock_status($product['stock_status'] ?? '')); ?></td>
                                <td><?php echo isset($product['stock_quantity']) ? esc_html($product['stock_quantity']) : '—'; ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field(self::NONCE_ACTION_IMPORT); ?>
                                        <input type="hidden" name="action" value="azinsanaat_import_product">
                                        <input type="hidden" name="product_id" value="<?php echo esc_attr($product['id']); ?>">
                                        <?php submit_button(__('دریافت و ساخت پیش‌نویس', 'azinsanaat-connection'), 'secondary', 'submit', false); ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?php
        }

        /**
         * Handles importing a product from remote API.
         */
        public static function handle_import_product(): void
        {
            if (!current_user_can('manage_options')) {
                wp_die(__('شما اجازه دسترسی ندارید.', 'azinsanaat-connection'));
            }

            check_admin_referer(self::NONCE_ACTION_IMPORT);

            if (!class_exists('WooCommerce')) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => __('افزونه ووکامرس فعال نیست.', 'azinsanaat-connection'),
                ]);
                wp_safe_redirect(self::get_products_page_url());
                exit;
            }

            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            if (!$product_id) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => __('شناسه محصول نامعتبر است.', 'azinsanaat-connection'),
                ]);
                wp_safe_redirect(self::get_products_page_url());
                exit;
            }

            $client = self::get_api_client();
            if (is_wp_error($client)) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => $client->get_error_message(),
                ]);
                wp_safe_redirect(self::get_products_page_url());
                exit;
            }

            $response = $client->get('products/' . $product_id);
            if (is_wp_error($response)) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => $response->get_error_message(),
                ]);
                wp_safe_redirect(self::get_products_page_url());
                exit;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $message = $body['message'] ?? sprintf(__('پاسخ نامعتبر از سرور (کد: %s).', 'azinsanaat-connection'), $status_code);
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => $message,
                ]);
                wp_safe_redirect(self::get_products_page_url());
                exit;
            }

            $product_data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($product_data)) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => __('پاسخ نامعتبر از سرور دریافت شد.', 'azinsanaat-connection'),
                ]);
                wp_safe_redirect(self::get_products_page_url());
                exit;
            }

            $result = self::create_pending_product($product_data);
            if (is_wp_error($result)) {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'error',
                    'message' => $result->get_error_message(),
                ]);
            } else {
                self::set_transient_message('azinsanaat_connection_import_notice', [
                    'type'    => 'success',
                    'message' => sprintf(__('محصول "%s" با موفقیت به حالت در انتظار بررسی ایجاد شد.', 'azinsanaat-connection'), $product_data['name'] ?? $product_id),
                ]);
            }

            wp_safe_redirect(self::get_products_page_url());
            exit;
        }

        /**
         * Creates a pending WooCommerce product locally.
         */
        protected static function create_pending_product(array $data)
        {
            $name = $data['name'] ?? '';
            if (!$name) {
                return new WP_Error('azinsanaat_missing_name', __('نام محصول در پاسخ API یافت نشد.', 'azinsanaat-connection'));
            }

            $sku = $data['sku'] ?? '';
            if ($sku && function_exists('wc_get_product_id_by_sku')) {
                $existing_id = wc_get_product_id_by_sku($sku);
                if ($existing_id) {
                    return new WP_Error('azinsanaat_product_exists', __('محصولی با این SKU قبلاً وجود دارد.', 'azinsanaat-connection'));
                }
            }

            $short_description = isset($data['short_description']) ? wp_kses_post($data['short_description']) : '';
            $short_description = preg_replace('/<img[^>]*>/i', '', $short_description);

            $post_data = [
                'post_title'   => wp_strip_all_tags($name),
                'post_status'  => 'pending',
                'post_type'    => 'product',
                'post_excerpt' => $short_description,
                'post_content' => isset($data['description']) ? wp_kses_post($data['description']) : '',
            ];

            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                return $post_id;
            }

            if ($sku) {
                update_post_meta($post_id, '_sku', sanitize_text_field($sku));
            }

            if (isset($data['regular_price'])) {
                update_post_meta($post_id, '_regular_price', wc_clean($data['regular_price']));
                update_post_meta($post_id, '_price', wc_clean($data['regular_price']));
            } elseif (isset($data['price'])) {
                update_post_meta($post_id, '_price', wc_clean($data['price']));
            }

            if (isset($data['stock_status'])) {
                update_post_meta($post_id, '_stock_status', sanitize_text_field($data['stock_status']));
            }

            if (isset($data['stock_quantity'])) {
                update_post_meta($post_id, '_stock', intval($data['stock_quantity']));
            }

            if (!empty($data['categories']) && is_array($data['categories'])) {
                $category_names = array_map(function ($category) {
                    return $category['name'] ?? '';
                }, $data['categories']);
                $category_names = array_filter($category_names);
                if (!empty($category_names)) {
                    wp_set_object_terms($post_id, $category_names, 'product_cat');
                }
            }

            if (!empty($data['tags']) && is_array($data['tags'])) {
                $tag_names = array_map(function ($tag) {
                    return $tag['name'] ?? '';
                }, $data['tags']);
                $tag_names = array_filter($tag_names);
                if (!empty($tag_names)) {
                    wp_set_object_terms($post_id, $tag_names, 'product_tag');
                }
            }

            $manage_stock = !empty($data['manage_stock']) ? 'yes' : 'no';
            update_post_meta($post_id, '_manage_stock', $manage_stock);

            if (!empty($data['images']) && is_array($data['images'])) {
                foreach ($data['images'] as $image) {
                    $image_url = $image['src'] ?? '';
                    if (!$image_url) {
                        continue;
                    }

                    $attachment_id = self::set_featured_image_from_url($post_id, esc_url_raw($image_url));
                    if (!is_wp_error($attachment_id) && $attachment_id) {
                        break;
                    }
                }
            }

            return $post_id;
        }

        protected static function set_featured_image_from_url(int $post_id, string $image_url)
        {
            if (empty($image_url)) {
                return false;
            }

            if (!function_exists('media_sideload_image')) {
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $attachment_id = media_sideload_image($image_url, $post_id, null, 'id');

            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
            }

            return $attachment_id;
        }

        protected static function format_stock_status(string $status): string
        {
            switch ($status) {
                case 'instock':
                    return __('موجود', 'azinsanaat-connection');
                case 'outofstock':
                    return __('ناموجود', 'azinsanaat-connection');
                case 'onbackorder':
                    return __('در انتظار تأمین', 'azinsanaat-connection');
                default:
                    return $status !== '' ? $status : '—';
            }
        }

        public static function register_product_meta_box(): void
        {
            add_meta_box(
                'azinsanaat-product-sync',
                __('همگام‌سازی با آذین صنعت', 'azinsanaat-connection'),
                [__CLASS__, 'render_product_meta_box'],
                'product',
                'side'
            );
        }

        public static function render_product_meta_box(WP_Post $post): void
        {
            $remote_id = get_post_meta($post->ID, self::META_REMOTE_ID, true);
            $last_sync = get_post_meta($post->ID, self::META_LAST_SYNC, true);

            wp_nonce_field('azinsanaat_save_product_meta', 'azinsanaat_product_meta_nonce');
            ?>
            <p>
                <label for="azinsanaat-remote-product-id"><?php esc_html_e('شناسه محصول در وب‌سرویس آذین صنعت', 'azinsanaat-connection'); ?></label>
                <input type="number" id="azinsanaat-remote-product-id" name="azinsanaat_remote_product_id" value="<?php echo esc_attr($remote_id); ?>" class="widefat" min="1" step="1">
            </p>
            <p class="description"><?php esc_html_e('با ذخیره محصول، قیمت و موجودی بر اساس اطلاعات این شناسه به‌روزرسانی می‌شود.', 'azinsanaat-connection'); ?></p>
            <?php if ($last_sync) :
                $timestamp = (int) $last_sync;
                $format = get_option('date_format') . ' - ' . get_option('time_format');
                $formatted = function_exists('wp_date') ? wp_date($format, $timestamp) : date_i18n($format, $timestamp);
                ?>
                <p><strong><?php esc_html_e('آخرین همگام‌سازی:', 'azinsanaat-connection'); ?></strong> <?php echo esc_html($formatted); ?></p>
            <?php endif; ?>
            <?php
        }

        public static function handle_save_product(int $post_id): void
        {
            if (!isset($_POST['azinsanaat_product_meta_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['azinsanaat_product_meta_nonce'])), 'azinsanaat_save_product_meta')) {
                return;
            }

            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
                return;
            }

            if (wp_is_post_revision($post_id)) {
                return;
            }

            if (!current_user_can('edit_post', $post_id)) {
                return;
            }

            $remote_id = isset($_POST['azinsanaat_remote_product_id']) ? absint(wp_unslash($_POST['azinsanaat_remote_product_id'])) : 0;

            if (!$remote_id) {
                delete_post_meta($post_id, self::META_REMOTE_ID);
                delete_post_meta($post_id, self::META_LAST_SYNC);
                return;
            }

            update_post_meta($post_id, self::META_REMOTE_ID, $remote_id);

            $result = self::sync_product_with_remote($post_id, $remote_id);

            if (is_wp_error($result)) {
                self::add_product_sync_notice($post_id, $result->get_error_message(), 'error');
            } else {
                self::add_product_sync_notice($post_id, __('قیمت و موجودی این محصول با موفقیت همگام‌سازی شد.', 'azinsanaat-connection'));
            }
        }

        public static function display_product_sync_notice(): void
        {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (!$screen || !isset($screen->post_type) || 'product' !== $screen->post_type) {
                return;
            }

            $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if (!$post_id) {
                return;
            }

            $key = self::get_product_sync_notice_key($post_id);
            $notice = get_transient($key);

            if (!$notice) {
                return;
            }

            delete_transient($key);

            $type = $notice['type'] ?? 'success';
            $message = $notice['message'] ?? '';

            if (!$message) {
                return;
            }

            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($type), esc_html($message));
        }

        protected static function add_product_sync_notice(int $post_id, string $message, string $type = 'success'): void
        {
            set_transient(
                self::get_product_sync_notice_key($post_id),
                [
                    'message' => $message,
                    'type'    => $type,
                ],
                MINUTE_IN_SECONDS
            );
        }

        protected static function get_product_sync_notice_key(int $post_id): string
        {
            return 'azinsanaat_product_sync_notice_' . $post_id;
        }

        protected static function sync_product_with_remote(int $product_id, int $remote_id)
        {
            $client = self::get_api_client();
            if (is_wp_error($client)) {
                return $client;
            }

            $response = $client->get('products/' . $remote_id);
            if (is_wp_error($response)) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code < 200 || $status_code >= 300) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $message = $body['message'] ?? __('خطا در دریافت اطلاعات محصول از وب‌سرویس.', 'azinsanaat-connection');

                return new WP_Error('azinsanaat_sync_failed', $message);
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) {
                return new WP_Error('azinsanaat_invalid_response', __('پاسخ نامعتبر از وب‌سرویس دریافت شد.', 'azinsanaat-connection'));
            }

            $clean_callback = function_exists('wc_clean') ? 'wc_clean' : 'sanitize_text_field';

            if (isset($data['regular_price']) && $data['regular_price'] !== '') {
                $regular_price = call_user_func($clean_callback, $data['regular_price']);
                update_post_meta($product_id, '_regular_price', $regular_price);
                update_post_meta($product_id, '_price', $regular_price);
            } elseif (isset($data['price']) && $data['price'] !== '') {
                $price = call_user_func($clean_callback, $data['price']);
                update_post_meta($product_id, '_price', $price);
                delete_post_meta($product_id, '_regular_price');
            }

            if (isset($data['stock_status']) && $data['stock_status'] !== '') {
                update_post_meta($product_id, '_stock_status', sanitize_text_field($data['stock_status']));
            }

            if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null && $data['stock_quantity'] !== '') {
                $quantity = (int) $data['stock_quantity'];
                update_post_meta($product_id, '_manage_stock', 'yes');
                update_post_meta($product_id, '_stock', $quantity);
            } else {
                update_post_meta($product_id, '_manage_stock', 'no');
                delete_post_meta($product_id, '_stock');
            }

            update_post_meta($product_id, self::META_LAST_SYNC, current_time('timestamp'));

            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }

            return true;
        }

        public static function run_scheduled_sync(): void
        {
            $products = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'meta_query'     => [
                    [
                        'key'     => self::META_REMOTE_ID,
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);

            if (empty($products)) {
                return;
            }

            foreach ($products as $product_id) {
                $remote_id = (int) get_post_meta($product_id, self::META_REMOTE_ID, true);
                if (!$remote_id) {
                    continue;
                }

                $result = self::sync_product_with_remote($product_id, $remote_id);

                if (is_wp_error($result)) {
                    error_log(sprintf('Azinsanaat Connection: sync failed for product #%1$d - %2$s', $product_id, $result->get_error_message()));
                }
            }
        }

        public static function register_cron_schedules(array $schedules): array
        {
            foreach (self::get_sync_intervals() as $key => $interval) {
                $schedules['azinsanaat_' . $key] = [
                    'interval' => $interval['interval'],
                    'display'  => $interval['label'],
                ];
            }

            return $schedules;
        }

        public static function ensure_cron_schedule(): void
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                self::schedule_event();
            }
        }

        protected static function schedule_event(?string $interval_key = null): void
        {
            $intervals = self::get_sync_intervals();

            if ($interval_key === null) {
                $options = get_option(self::OPTION_KEY);
                $interval_key = is_array($options) && isset($options['sync_interval']) ? $options['sync_interval'] : '15min';
            }

            if (!isset($intervals[$interval_key])) {
                $interval_key = '15min';
            }

            $recurrence = 'azinsanaat_' . $interval_key;

            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + $intervals[$interval_key]['interval'], $recurrence, self::CRON_HOOK);
            }
        }

        protected static function clear_scheduled_event(): void
        {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        public static function handle_options_updated($old_value, $value, $option): void
        {
            $old_interval = is_array($old_value) && isset($old_value['sync_interval']) ? $old_value['sync_interval'] : '';
            $new_interval = is_array($value) && isset($value['sync_interval']) ? $value['sync_interval'] : '';

            if ($old_interval === $new_interval) {
                return;
            }

            self::clear_scheduled_event();
            self::schedule_event($new_interval);
        }

        public static function activate(): void
        {
            self::clear_scheduled_event();
            self::schedule_event();
        }

        public static function deactivate(): void
        {
            self::clear_scheduled_event();
        }

        /**
         * Retrieves an API client instance.
         */
        protected static function get_api_client()
        {
            $options = get_option(self::OPTION_KEY);
            if (empty($options['store_url']) || empty($options['consumer_key']) || empty($options['consumer_secret'])) {
                return new WP_Error('azinsanaat_missing_credentials', __('ابتدا تنظیمات اتصال را تکمیل کنید.', 'azinsanaat-connection'));
            }

            return new class($options) {
                private $options;

                public function __construct(array $options)
                {
                    $this->options = $options;
                }

                public function get(string $endpoint, array $params = [])
                {
                    $url = $this->build_url($endpoint, $params);
                    $response = wp_remote_get($url, [
                        'timeout' => 30,
                        'headers' => [
                            'Accept' => 'application/json',
                        ],
                    ]);

                    if (is_wp_error($response)) {
                        return $response;
                    }

                    $status_code = wp_remote_retrieve_response_code($response);
                    if ($status_code === 401) {
                        return new WP_Error(
                            'azinsanaat_unauthorized',
                            __('دسترسی به API امکان‌پذیر نیست. کلیدها را بررسی کنید.', 'azinsanaat-connection')
                        );
                    }

                    if ($status_code === 403) {
                        return new WP_Error(
                            'azinsanaat_forbidden',
                            __('کلیدهای API اجازه مشاهده این بخش را ندارند. در ووکامرس (ووکامرس ← تنظیمات ← پیشرفته ← REST API) سطح دسترسی را روی «خواندن» یا «خواندن/نوشتن» تنظیم کنید.', 'azinsanaat-connection')
                        );
                    }

                    return $response;
                }

                private function build_url(string $endpoint, array $params = []): string
                {
                    $base = trailingslashit($this->options['store_url']);
                    $endpoint = ltrim($endpoint, '/');
                    $url = $base . 'wp-json/wc/v3/' . $endpoint;
                    $query_args = array_merge($params, [
                        'consumer_key'    => $this->options['consumer_key'],
                        'consumer_secret' => $this->options['consumer_secret'],
                    ]);

                    return add_query_arg($query_args, $url);
                }
            };
        }

        /**
         * Stores a transient admin notice message.
         */
        protected static function set_transient_message(string $key, array $message): void
        {
            set_transient($key, $message, MINUTE_IN_SECONDS);
        }

        /**
         * Retrieves a transient message and deletes it.
         */
        protected static function get_transient_message(string $key)
        {
            $message = get_transient($key);
            if ($message) {
                delete_transient($key);
            }

            return $message;
        }

        protected static function get_settings_page_url(): string
        {
            return admin_url('admin.php?page=azinsanaat-connection');
        }

        protected static function get_products_page_url(): string
        {
            return admin_url('admin.php?page=azinsanaat-connection-products');
        }
    }
}

register_activation_hook(__FILE__, ['Azinsanaat_Connection', 'activate']);
register_deactivation_hook(__FILE__, ['Azinsanaat_Connection', 'deactivate']);

Azinsanaat_Connection::init();
