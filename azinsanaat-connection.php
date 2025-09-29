<?php
/**
 * Plugin Name: Azinsanaat Connection
 * Description: اتصال به آذین صنعت و همگام‌سازی محصولات از طریق API ووکامرس.
 * Version:     1.0.1
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

        /**
         * Bootstraps plugin hooks.
         */
        public static function init(): void
        {
            if (!is_admin()) {
                return;
            }

            add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
            add_action('admin_init', [__CLASS__, 'register_settings']);
            add_action('admin_post_azinsanaat_test_connection', [__CLASS__, 'handle_test_connection']);
            add_action('admin_post_azinsanaat_import_product', [__CLASS__, 'handle_import_product']);
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

            return $output;
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
                $response = $client->get('products', ['per_page' => 50, 'status' => 'publish']);
                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                } else {
                    $status = wp_remote_retrieve_response_code($response);
                    if ($status >= 200 && $status < 300) {
                        $products = json_decode(wp_remote_retrieve_body($response), true);
                        if (!is_array($products)) {
                            $products = [];
                            $error_message = __('پاسخ نامعتبر از سرور دریافت شد.', 'azinsanaat-connection');
                        }
                    } else {
                        $body = json_decode(wp_remote_retrieve_body($response), true);
                        $error_message = $body['message'] ?? sprintf(__('پاسخ نامعتبر از سرور (کد: %s).', 'azinsanaat-connection'), $status);
                    }
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
                            <th><?php esc_html_e('شناسه', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('نام', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('قیمت', 'azinsanaat-connection'); ?></th>
                            <th><?php esc_html_e('عملیات', 'azinsanaat-connection'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($products as $product) : ?>
                            <tr>
                                <td><?php echo esc_html($product['id']); ?></td>
                                <td><?php echo esc_html($product['name']); ?></td>
                                <td><?php echo isset($product['price']) ? esc_html($product['price']) : '—'; ?></td>
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

            $post_data = [
                'post_title'   => wp_strip_all_tags($name),
                'post_status'  => 'pending',
                'post_type'    => 'product',
                'post_excerpt' => isset($data['short_description']) ? wp_kses_post($data['short_description']) : '',
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

            return $post_id;
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
                            __('کلیدهای API اجازه مشاهده این بخش را ندارند. سطح دسترسی خواندن را در ووکامرس بررسی کنید.', 'azinsanaat-connection')
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

Azinsanaat_Connection::init();
